<?php

namespace App\Http\Controllers;

use App\Models\{Location, Organization, PublicDeliveryLocation, PublicPickupPlace};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Gate};
use Illuminate\Validation\Rule;

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
            'deliveries' => PublicDeliveryLocation::whereIn('organization_id', $organizations->modelKeys())
                ->with(['place', 'organization'])->orderBy('organization_id')->orderBy('id')->get(),
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
        $data = $request->validate(['is_active' => ['required', 'boolean'], 'custom_delivery_enabled' => ['sometimes', 'boolean'], 'delivery_area' => ['nullable', 'required_if:custom_delivery_enabled,1', 'string', 'max:500']]);
        DB::transaction(function () use ($model, $data) {
            Organization::whereKey($model->organization_id)->lockForUpdate()->firstOrFail();
            $model->update(['is_active' => (bool) $data['is_active']]);
            if (array_key_exists('custom_delivery_enabled', $data)) $model->update(['custom_delivery_enabled' => (bool) $data['custom_delivery_enabled'], 'delivery_area' => $data['delivery_area'] ?? null]);
        });
        return redirect()->route('public-deliveries.index')->with('status', $model->is_active ? 'Luogo riattivato.' : 'Luogo disattivato per le nuove prenotazioni. Le prenotazioni già confermate restano valide.');
    }
}
