<?php

namespace App\Http\Controllers;

use App\Domain\Rentals\PublicVehiclePhoto;
use App\Domain\Rentals\PublicVehicleSearch;
use App\Domain\Rentals\PublicPickupDirectory;
use App\Http\Requests\PublicCarSearchRequest;
use App\Models\{PublicRentalOffer, VehiclePricelist};
use App\Models\{PublicPickupPlace, PublicDeliveryLocation};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use App\Services\Geocoding\{NominatimSearch, PlaceSearchUnavailable, PlaceSelection};

class PublicCarSearchController extends Controller
{
    public function __construct(private PublicVehiclePhoto $photos) {}

    public function index(PublicCarSearchRequest $request, PublicVehicleSearch $search)
    {
        return $this->results($request, $search, false);
    }

    public function preview(PublicCarSearchRequest $request, PublicVehicleSearch $search)
    {
        return $this->results($request, $search, true);
    }

    public function show(PublicCarSearchRequest $request, PublicVehicleSearch $search, int $pricelist)
    {
        return $this->detail($request, $search, $pricelist, false);
    }

    public function previewShow(PublicCarSearchRequest $request, PublicVehicleSearch $search, int $pricelist)
    {
        return $this->detail($request, $search, $pricelist, true);
    }

    public function photo(Request $request, int $pricelist)
    {
        return $this->image($request, $pricelist, false);
    }

    public function previewPhoto(Request $request, int $pricelist)
    {
        return $this->image($request, $pricelist, true);
    }

    /** Keep old offer URLs distinct from the new pricelist URLs. */
    public function legacy(Request $request, int $offer)
    {
        $preview = $request->routeIs('public-cars.preview.*');
        $record = PublicRentalOffer::findOrFail($offer);
        $pricelist = $this->scope($request, $preview)->whereKey($record->pricelist_id)
            ->where('vehicle_id', $record->vehicle_id)->where('renter_org_id', $record->organization_id)->firstOrFail();
        $suffix = $request->routeIs('*.legacy.photo') ? 'photo'
            : ($request->routeIs('*.legacy.booking') ? 'booking.create' : 'show');
        $filters = $request->only(['pickup_at', 'return_at', 'place_id']);
        if (empty($filters['place_id'])) {
            $filters['place_id'] = PublicDeliveryLocation::available()->where('organization_id', $record->organization_id)
                ->where('location_id', $record->location_id)->value('public_pickup_place_id');
            abort_unless($filters['place_id'], 404);
        }
        return redirect()->route(($preview ? 'public-cars.preview' : 'public-cars').'.'.$suffix,
            ['pricelist' => $pricelist->id] + $filters)->header('Cache-Control', 'private, no-store');
    }

    public function expiredCheckout(Request $request)
    {
        return redirect()->route($request->routeIs('public-cars.preview.*') ? 'public-cars.preview.index' : 'public-cars.index', [], 303)
            ->withErrors(['booking' => 'La pagina di prenotazione è stata aggiornata. Cerca nuovamente l’auto e riapri il riepilogo.']);
    }

    private function scope(Request $request, bool $preview): Builder
    {
        if (!$preview) {
            return VehiclePricelist::forPublicRental();
        }

        Gate::authorize('vehicle_pricing.update');
        abort_unless($request->user()?->is_active, 403);
        $query = VehiclePricelist::forPublicRental();
        if (!$request->user()->hasRole('admin')) {
            abort_unless($request->user()->organization?->is_active, 403);
            $query->where('renter_org_id', $request->user()->organization_id);
        }
        return $query;
    }

    private function results(PublicCarSearchRequest $request, PublicVehicleSearch $search, bool $preview)
    {
        $scope = $this->scope($request, $preview);
        $filters = \Illuminate\Support\Arr::except($request->validated(), ['destination']);
        if (!empty($filters['request_custom_return'])) unset($filters['place_id'], $filters['city']);
        $searched = !empty($filters['pickup_at']) && !empty($filters['return_at']);
        $deliveryPoint = !empty($filters['delivery_place'])
            ? app(PlaceSelection::class)->resolve($filters['delivery_place'], $filters['delivery_address']) : null;
        $deliveryLookupNeeded = $searched && !empty($filters['request_delivery']) && !$deliveryPoint;
        $placeChoices = []; $placeSearchError = null; $mapCenter = null;
        if ($deliveryLookupNeeded) {
            try {
                $mapPlaces = app(NominatimSearch::class)->searchArea($filters['delivery_address']);
                // Areas position the map; only street-level results receive selection tokens.
                $precisePlaces = collect($mapPlaces)->where('zoom', '>=', 17)
                    ->map(fn ($place) => \Illuminate\Support\Arr::except($place, ['zoom']))->values()->all();
                $placeChoices = app(PlaceSelection::class)->issue($precisePlaces);
                if (count($mapPlaces) === 1) $mapCenter = $mapPlaces[0];
            } catch (PlaceSearchUnavailable $exception) {
                $placeSearchError = $exception->getMessage();
            }
        }
        $facets = (clone $scope)->with(['vehicle', 'renter'])->get();
        $deliveries = PublicDeliveryLocation::available()->when($preview && !$request->user()->hasRole('admin'),
            fn ($q) => $q->where('organization_id', $request->user()->organization_id));
        $places = PublicPickupPlace::whereIn('id', $deliveries->select('public_pickup_place_id'))
            ->orderBy('city')->orderBy('name')->get();
        $destinations = app(PublicPickupDirectory::class)->destinations($places);
        $matching = $searched && !$deliveryLookupNeeded ? $search->search($scope, \Illuminate\Support\Arr::except($filters, ['supplier'])) : collect();
        $supplierMatches = $matching;
        $vehicleFilters = ['budget', 'q', 'segment', 'transmission', 'fuel_type', 'seats'];
        if ($searched && !$deliveryLookupNeeded && collect(\Illuminate\Support\Arr::only($filters, $vehicleFilters))
            ->contains(fn ($value) => $value !== null && $value !== '')) {
            // Keep supplier choices tied to the location and dates even when a vehicle filter returns no cars.
            $supplierMatches = $search->search($scope, \Illuminate\Support\Arr::except($filters, [...$vehicleFilters, 'supplier']));
        }
        $results = empty($filters['supplier']) ? $matching : $matching->where('supplier_id', (int) $filters['supplier'])->values();
        $results = $search->cheapestPerProduct($results, $filters['sort'] ?? 'price_asc');
        $perPage = (int) config('public_cars.per_page');
        $page = (int) ($filters['page'] ?? 1);
        $paginator = new LengthAwarePaginator($results->forPage($page, $perPage)->values(), $results->count(), $perPage, $page, [
            'path' => $request->url(), 'query' => array_filter($filters, fn ($value) => $value !== null && $value !== ''),
        ]);
        $showDeliverySuppliers = $deliveryPoint && empty($filters['supplier']);
        $supplierRows = ($deliveryPoint ? $matching : collect())->groupBy('supplier_id')->map(function ($cars) use ($search) {
            $first = $cars->first();
            $products = $search->cheapestPerProduct($cars);
            return ['id' => $first['supplier_id'], 'name' => $first['organization'],
                'distance_km' => $first['delivery_distance_km'], 'origin' => $first['delivery_origin_name'],
                'count' => $products->count(), 'from_cents' => $products->min('total_cents'), 'area' => $first['delivery_area']];
        })->sort(fn ($a, $b) => ($a['distance_km'] <=> $b['distance_km']) ?: strcmp($a['name'], $b['name']))->values();
        $supplierResults = new LengthAwarePaginator($supplierRows->forPage($page, $perPage)->values(), $supplierRows->count(), $perPage, $page, [
            'path' => $request->url(), 'query' => array_filter($filters, fn ($value) => $value !== null && $value !== ''),
        ]);

        return response()->view('public-cars.index', [
            'preview' => $preview, 'routePrefix' => $preview ? 'public-cars.preview' : 'public-cars',
            'filters' => $filters, 'searched' => $searched, 'results' => $paginator,
            'deliveryPoint' => $deliveryPoint, 'deliveryLookupNeeded' => $deliveryLookupNeeded,
            'placeChoices' => $placeChoices, 'placeSearchError' => $placeSearchError, 'mapCenter' => $mapCenter,
            'showDeliverySuppliers' => $showDeliverySuppliers, 'supplierResults' => $supplierResults,
            'places' => $places,
            'destinations' => $destinations,
            'selectedPlace' => $places->firstWhere('id', $filters['place_id'] ?? null),
            'suppliers' => $facets->pluck('renter')->whereIn('id', $supplierMatches->pluck('supplier_id'))->unique('id')->sortBy('name')->values(),
            'cities' => $places->pluck('city')->filter()->unique(fn ($city) => mb_strtolower($city))->sort()->values(),
            'segments' => $facets->pluck('vehicle.segment')->filter()->unique(fn ($segment) => mb_strtolower($segment))->sort()->values(),
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, follow');
    }

    private function detail(PublicCarSearchRequest $request, PublicVehicleSearch $search, int $pricelist, bool $preview)
    {
        $scope = $this->scope($request, $preview)->whereKey($pricelist);
        abort_unless((clone $scope)->exists(), 404);
        $filters = $request->validated();
        unset($filters['budget'], $filters['page'], $filters['q'], $filters['segment'], $filters['seats'], $filters['fuel_type'], $filters['transmission'], $filters['supplier']);
        $result = $search->search($scope, $filters)->first();
        $returnPlaces = $result && !empty($filters['request_delivery'])
            ? $search->deliveryOptions($filters, $result['delivery_destination'] ?? null)
                ->where('organization_id', $result['supplier_id'])->pluck('place')->unique('id')->values()
            : collect();

        return response()->view('public-cars.show', [
            'car' => $result, 'filters' => $request->validated(), 'preview' => $preview,
            'returnPoint' => !empty($filters['request_custom_return'])
                ? app(\App\Services\Geocoding\PlaceSelection::class)->resolve($filters['return_place'], $filters['return_address'], 'public-return') : null,
            'routePrefix' => $preview ? 'public-cars.preview' : 'public-cars', 'returnPlaces' => $returnPlaces,
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, follow');
    }

    private function image(Request $request, int $pricelist, bool $preview)
    {
        $model = $this->scope($request, $preview)->with('vehicle.media')->findOrFail($pricelist);
        abort_unless(PublicDeliveryLocation::available()->where('organization_id', $model->renter_org_id)->exists(), 404);
        $photo = $this->photos->forVehicle($model->vehicle);
        abort_unless($photo, 404);

        return Storage::disk($photo['disk'])->response($photo['path'], null, [
            'Content-Type' => $photo['mime_type'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
