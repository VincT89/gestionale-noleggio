<?php

namespace App\Http\Controllers;

use App\Domain\Rentals\PublicVehiclePhoto;
use App\Domain\Rentals\PublicVehicleSearch;
use App\Http\Requests\PublicCarSearchRequest;
use App\Models\PublicRentalOffer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

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

    public function show(PublicCarSearchRequest $request, PublicVehicleSearch $search, int $offer)
    {
        return $this->detail($request, $search, $offer, false);
    }

    public function previewShow(PublicCarSearchRequest $request, PublicVehicleSearch $search, int $offer)
    {
        return $this->detail($request, $search, $offer, true);
    }

    public function photo(Request $request, int $offer)
    {
        return $this->image($request, $offer, false);
    }

    public function previewPhoto(Request $request, int $offer)
    {
        return $this->image($request, $offer, true);
    }

    private function scope(Request $request, bool $preview): Builder
    {
        if (!$preview) {
            return PublicRentalOffer::published();
        }

        Gate::authorize('vehicle_pricing.update');
        abort_unless($request->user()?->is_active, 403);
        $query = PublicRentalOffer::eligible();
        if (!$request->user()->hasRole('admin')) {
            abort_unless($request->user()->organization?->is_active, 403);
            $query->where('organization_id', $request->user()->organization_id);
        }
        return $query;
    }

    private function results(PublicCarSearchRequest $request, PublicVehicleSearch $search, bool $preview)
    {
        $scope = $this->scope($request, $preview);
        $filters = $request->validated();
        $searched = !empty($filters['pickup_at']) && !empty($filters['return_at']);
        $facets = (clone $scope)->with(['location', 'vehicle'])->get();
        $results = $searched ? $search->search($scope, $filters) : collect();
        $perPage = (int) config('public_cars.per_page');
        $page = (int) ($filters['page'] ?? 1);
        $paginator = new LengthAwarePaginator($results->forPage($page, $perPage)->values(), $results->count(), $perPage, $page, [
            'path' => $request->url(), 'query' => array_filter($filters, fn ($value) => $value !== null && $value !== ''),
        ]);

        return response()->view('public-cars.index', [
            'preview' => $preview, 'routePrefix' => $preview ? 'public-cars.preview' : 'public-cars',
            'filters' => $filters, 'searched' => $searched, 'results' => $paginator,
            'cities' => $facets->pluck('location.city')->filter()->unique(fn ($city) => mb_strtolower($city))->sort()->values(),
            'segments' => $facets->pluck('vehicle.segment')->filter()->unique(fn ($segment) => mb_strtolower($segment))->sort()->values(),
        ])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, follow');
    }

    private function detail(PublicCarSearchRequest $request, PublicVehicleSearch $search, int $offer, bool $preview)
    {
        $scope = $this->scope($request, $preview)->whereKey($offer);
        abort_unless((clone $scope)->exists(), 404);
        $filters = $request->validated();
        unset($filters['budget'], $filters['page'], $filters['q'], $filters['city'], $filters['segment'], $filters['seats'], $filters['fuel_type'], $filters['transmission']);
        $result = $search->search($scope, $filters)->first();

        return response()->view('public-cars.show', [
            'car' => $result, 'filters' => $request->validated(), 'preview' => $preview,
            'routePrefix' => $preview ? 'public-cars.preview' : 'public-cars',
        ])->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, follow');
    }

    private function image(Request $request, int $offer, bool $preview)
    {
        $model = $this->scope($request, $preview)->with('vehicle.media')->findOrFail($offer);
        $photo = $this->photos->forVehicle($model->vehicle);
        abort_unless($photo, 404);

        return Storage::disk($photo['disk'])->response($photo['path'], null, [
            'Content-Type' => $photo['mime_type'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
