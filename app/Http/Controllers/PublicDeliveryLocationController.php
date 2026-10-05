<?php

namespace App\Http\Controllers;

use App\Models\{Location, Organization, PublicDeliveryLocation, PublicPickupPlace};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Services\Geocoding\{NominatimSearch, PlaceSearchUnavailable, PlaceSelection};

class PublicDeliveryLocationController extends Controller
{
    private function organizations(Request $request)
    {
        Gate::authorize('vehicle_pricing.update');
        abort_unless($request->user()?->is_active && $request->user()?->organization?->is_active && $request->user()->hasAnyRole(['admin', 'renter']), 403);
        return Organization::where('is_active', true)->when(!$request->user()->hasRole('admin'),
            fn ($q) => $q->whereKey($request->user()->organization_id));
    }

    public function index(Request $request)
    {
        $organizations = $this->organizations($request)->orderBy('name')->get();
        return view('public-cars.delivery-locations', [
            'organizations' => $organizations,
            'places' => PublicPickupPlace::orderBy('city')->orderBy('name')->get(),
            'origins' => Location::whereIn('organization_id', $organizations->modelKeys())->orderBy('name')->get(),
            'deliveries' => PublicDeliveryLocation::whereIn('organization_id', $organizations->modelKeys())
                ->with(['place', 'organization', 'origin'])->orderBy('organization_id')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $organizations = $this->organizations($request);
        $data = $request->validate([
            'organization_id' => ['required', 'integer'],
            'place_id' => ['bail', 'nullable', 'integer', 'exists:public_pickup_places,id'],
            'name' => [Rule::excludeIf($request->filled('place_id')), 'required_without:place_id', 'string', 'max:191'],
            'city' => ['required_without:place_id', 'nullable', 'string', 'max:128'],
            'kind' => ['required_without:place_id', 'nullable', Rule::in(array_keys(PublicPickupPlace::KINDS))],
            'address_line' => ['nullable', 'string', 'max:191'],
            'country_code' => ['required_without:place_id', 'nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'confirm_delivery' => ['accepted'],
        ], ['confirm_delivery.accepted' => 'Conferma che puoi consegnare e ritirare le auto in questo luogo alle condizioni delle offerte.',
            'name.required_without' => 'Indica il nome del nuovo luogo.', 'city.required_without' => 'Indica la città del nuovo luogo.']);
        $organization = $organizations->findOrFail($data['organization_id']);
        DB::transaction(function () use ($data, $organization) {
            // Share the organization lock used when reserving a vehicle.
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            if (!empty($data['place_id'])) {
                $place = PublicPickupPlace::findOrFail($data['place_id']);
            } else {
                $fields = array_intersect_key($data, array_flip(['name', 'city', 'kind', 'address_line', 'country_code']));
                $fields = array_map(fn ($value) => is_string($value) ? preg_replace('/\s+/u', ' ', trim($value)) : $value, $fields);
                $place = PublicPickupPlace::firstOrCreate(['identity_key' => PublicPickupPlace::identity($fields)], $fields);
            }
            $delivery = PublicDeliveryLocation::where('organization_id', $organization->id)->where('public_pickup_place_id', $place->id)->first();
            if ($delivery) {
                $delivery->update(['is_active' => true]);
                return;
            }
            // A real organization-owned location makes the selected point available in ERA contracts too.
            $location = Location::create(['organization_id' => $organization->id] + $place->only(['name', 'city', 'address_line', 'country_code']));
            PublicDeliveryLocation::create(['organization_id' => $organization->id, 'public_pickup_place_id' => $place->id,
                'location_id' => $location->id, 'is_active' => true]);
        });
        return redirect()->route('public-deliveries.index')->with('status', 'Luogo di consegna attivato. Le auto compariranno soltanto se disponibili per il periodo cercato.');
    }

    public function update(Request $request, int $delivery)
    {
        $organizations = $this->organizations($request);
        $model = PublicDeliveryLocation::whereIn('organization_id', $organizations->select('id'))->findOrFail($delivery);
        $data = $request->validate([
            'is_active' => ['required', 'boolean'], 'custom_delivery_enabled' => ['sometimes', 'boolean'],
            'delivery_area' => ['nullable', 'required_if:custom_delivery_enabled,1', 'string', 'max:500'],
            'delivery_origin_location_id' => ['nullable', 'required_with:delivery_radius_km', 'integer', 'min:1'],
            'delivery_radius_km' => ['nullable', 'required_with:delivery_origin_location_id', 'numeric', 'min:0.01', 'max:999999.99', 'decimal:0,2'],
            'origin_choice' => ['nullable', 'uuid'], 'origin_query' => ['nullable', 'string', 'min:3', 'max:500'],
            'locate_origin' => ['nullable', 'boolean'],
        ], ['delivery_origin_location_id.*' => 'Scegli la sede da cui parti per la consegna.',
            'delivery_radius_km.*' => 'Indica il raggio di consegna in chilometri, maggiore di zero e con al massimo due decimali.']);
        $origin = empty($data['delivery_origin_location_id']) ? null
            : Location::where('organization_id', $model->organization_id)->findOrFail($data['delivery_origin_location_id']);
        $context = 'origin:'.$model->id.':'.$origin?->id;
        if ($request->boolean('locate_origin')) {
            if (!$origin) throw ValidationException::withMessages(['delivery_origin_location_id' => 'Scegli prima la sede di partenza.']);
            $query = $data['origin_query'] ?? implode(', ', array_filter([$origin->address_line, $origin->city, $origin->country_code]));
            $choices = []; $lookupError = null;
            try {
                $choices = app(PlaceSelection::class)->issue(app(NominatimSearch::class)->search($query), $context);
            } catch (PlaceSearchUnavailable $exception) {
                $lookupError = $exception->getMessage();
            }
            return response()->view('public-cars.delivery-origin', [
                'delivery' => $model->load(['place', 'organization']), 'origin' => $origin, 'query' => $query,
                'choices' => $choices, 'lookupError' => $lookupError,
                'fields' => array_intersect_key($data, array_flip(['is_active', 'custom_delivery_enabled', 'delivery_area', 'delivery_origin_location_id', 'delivery_radius_km'])),
            ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
        }
        $point = empty($data['origin_choice']) ? null : app(PlaceSelection::class)->resolve($data['origin_choice'], context: $context);
        if (!empty($data['origin_choice']) && !$point) {
            throw ValidationException::withMessages(['origin_choice' => 'La posizione selezionata è scaduta o appartiene a un’altra sede. Cercala nuovamente.']);
        }
        if ($origin && !$point && (!is_numeric($origin->lat) || !is_numeric($origin->lng)
            || abs((float) $origin->lat) > 90 || abs((float) $origin->lng) > 180)) {
            throw ValidationException::withMessages(['origin_choice' => 'Cerca e conferma la posizione della sede con OpenStreetMap prima di salvare il raggio.']);
        }
        DB::transaction(function () use ($model, $data, $origin, $point) {
            Organization::whereKey($model->organization_id)->lockForUpdate()->firstOrFail();
            if ($point && $origin) {
                Location::where('organization_id', $model->organization_id)->whereKey($origin->id)->lockForUpdate()->firstOrFail()
                    ->update(['lat' => $point['lat'], 'lng' => $point['lng']]);
            }
            $model->update(['is_active' => (bool) $data['is_active']]);
            if (array_key_exists('custom_delivery_enabled', $data)) $model->update(['custom_delivery_enabled' => (bool) $data['custom_delivery_enabled'], 'delivery_area' => $data['delivery_area'] ?? null]);
            $coverage = array_intersect_key($data, array_flip(['delivery_origin_location_id', 'delivery_radius_km']));
            if ($coverage) $model->update($coverage);
        });
        return redirect()->route('public-deliveries.index')->with('status', array_key_exists('custom_delivery_enabled', $data)
            ? 'Servizio di consegna aggiornato.'
            : ($model->is_active ? 'Luogo riattivato.' : 'Luogo disattivato per le nuove prenotazioni. Le prenotazioni già confermate restano valide.'));
    }
}
