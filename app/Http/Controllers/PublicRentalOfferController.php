<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Organization;
use App\Models\PublicRentalOffer;
use App\Models\VehiclePricelist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PublicRentalOfferController extends Controller
{
    private function scope(Request $request, Builder $query, string $organizationColumn = 'organization_id'): Builder
    {
        Gate::authorize('vehicle_pricing.update');
        abort_unless($request->user()?->is_active, 403);
        if (!$request->user()->hasRole('admin')) {
            abort_unless($request->user()->organization?->is_active, 403);
            $query->where($organizationColumn, $request->user()->organization_id);
        }
        return $query;
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'], 'organization_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = $this->scope($request, PublicRentalOffer::query());
        if (!empty($filters['organization_id'])) $query->where('organization_id', $filters['organization_id']);
        foreach (preg_split('/\s+/', trim($filters['q'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $query->where(fn (Builder $q) => $q
                ->whereHas('vehicle', fn (Builder $vehicle) => $vehicle->where(fn (Builder $v) =>
                    $v->where('plate', 'like', '%'.$word.'%')->orWhere('make', 'like', '%'.$word.'%')->orWhere('model', 'like', '%'.$word.'%')))
                ->orWhereHas('organization', fn (Builder $organization) => $organization->where('name', 'like', '%'.$word.'%')));
        }
        $offers = $query->with(['vehicle', 'organization', 'location', 'pricelist'])
            ->latest()->orderByDesc('id')->paginate(20)->withQueryString();
        $editing = $request->filled('edit')
            ? $this->scope($request, PublicRentalOffer::query())->findOrFail($request->integer('edit')) : null;

        return view('public-cars.manage', [
            'offers' => $offers, 'editing' => $editing,
            'filters' => $filters,
            'organizations' => Organization::whereIn('id', $this->scope($request, PublicRentalOffer::query())->select('organization_id')->distinct())
                ->orderBy('name')->get(['id', 'name']),
            'pricelists' => $this->scope($request, VehiclePricelist::query(), 'renter_org_id')
                ->where('status', 'active')->where('active_flag', true)->where('currency', 'EUR')
                ->whereHas('vehicle', fn (Builder $q) => $q->where('is_active', true))
                ->whereHas('renter', fn (Builder $q) => $q->where('is_active', true))
                ->with(['vehicle', 'renter'])->get(),
            'locations' => $this->scope($request, Location::query())
                ->whereHas('organization', fn (Builder $q) => $q->where('is_active', true))
                ->with('organization')->orderBy('city')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        return $this->save($request, new PublicRentalOffer);
    }

    public function update(Request $request, int $offer)
    {
        return $this->save($request, $this->scope($request, PublicRentalOffer::query())->findOrFail($offer));
    }

    private function save(Request $request, PublicRentalOffer $offer)
    {
        $this->scope($request, PublicRentalOffer::query());
        $data = $request->validate([
            'pricelist_id' => ['required', 'integer'],
            'location_id' => ['required', 'integer'],
            'description' => ['nullable', 'string', 'max:2000'],
            'prices_include_vat' => ['sometimes', 'boolean'],
            'is_published' => ['sometimes', 'boolean'],
            'deposit_euros' => ['sometimes', 'required', 'string', 'regex:/^[0-9]{1,8}([.,][0-9]{1,2})?$/'],
        ], [
            'deposit_euros.required' => 'Inserisci la cauzione, oppure 0 se non è prevista.',
            'deposit_euros.string' => 'Inserisci la cauzione in euro.',
            'deposit_euros.regex' => 'La cauzione deve essere un importo positivo o zero, con al massimo due decimali.',
        ]);
        $depositCents = null;
        if (array_key_exists('deposit_euros', $data)) {
            $parts = explode('.', str_replace(',', '.', $data['deposit_euros']));
            $depositCents = ((int) $parts[0] * 100) + (int) str_pad($parts[1] ?? '', 2, '0');
            if ($depositCents > 4294967295) {
                throw ValidationException::withMessages(['deposit_euros' => 'La cauzione supera l’importo supportato dal listino.']);
            }
        }
        $pricelist = $this->scope($request, VehiclePricelist::query(), 'renter_org_id')
            ->where('status', 'active')->where('active_flag', true)->where('currency', 'EUR')
            ->whereHas('vehicle', fn (Builder $q) => $q->where('is_active', true)
                ->whereHas('adminOrganization', fn (Builder $owner) => $owner->where('is_active', true)))
            ->whereHas('renter', fn (Builder $q) => $q->where('is_active', true))
            ->find($data['pricelist_id']);
        if (!$pricelist) {
            throw ValidationException::withMessages(['pricelist_id' => 'Scegli un listino attivo di un’organizzazione che puoi gestire.']);
        }
        if (!Location::where('organization_id', $pricelist->renter_org_id)->whereKey($data['location_id'])->exists()) {
            throw ValidationException::withMessages(['location_id' => 'La sede deve appartenere all’organizzazione del listino.']);
        }
        if ($request->boolean('is_published') && !$request->boolean('prices_include_vat')) {
            throw ValidationException::withMessages(['prices_include_vat' => 'Prima di pubblicare, verifica che il listino contenga prezzi finali al pubblico, IVA compresa.']);
        }
        $request->validate(['pricelist_id' => [Rule::unique('public_rental_offers', 'pricelist_id')->ignore($offer->id)]]);
        $duplicate = PublicRentalOffer::where('vehicle_id', $pricelist->vehicle_id)->where('organization_id', $pricelist->renter_org_id)
            ->when($offer->exists, fn (Builder $q) => $q->whereKeyNot($offer->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['pricelist_id' => 'Esiste già un’offerta per questo veicolo e questa organizzazione: modifica quella esistente.']);
        }

        $offer->fill([
            'vehicle_id' => $pricelist->vehicle_id, 'organization_id' => $pricelist->renter_org_id,
            'location_id' => $data['location_id'], 'pricelist_id' => $pricelist->id,
            'description' => $data['description'] ?? null,
            'prices_include_vat' => $request->boolean('prices_include_vat'),
            'is_published' => $request->boolean('is_published'),
        ]);
        if (!$offer->exists) {
            $offer->created_by = $request->user()->id;
        }
        DB::transaction(function () use ($pricelist, $offer, $depositCents) {
            if ($depositCents !== null) {
                $pricelist->deposit_cents = $depositCents;
                $pricelist->save();
            }
            $offer->save();
        });

        return redirect()->route('public-offers.index')->with('status', $offer->is_published ? 'Offerta pubblicata. La visibilità dipende dalla disponibilità nel periodo cercato.' : 'Offerta salvata in bozza.');
    }
}
