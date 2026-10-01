<?php

namespace App\Http\Controllers;

use App\Models\{Vehicle, VehicleProduct};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VehicleProductController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $query = VehicleProduct::query()->withCount('vehicles');
        if (!empty($filters['q'])) {
            $query->where('name', 'like', '%'.$filters['q'].'%');
        }

        return view('fleet-products.index', [
            'products' => $query->orderBy('name')->orderBy('id')->paginate(20)->withQueryString(),
            'query' => $filters['q'] ?? '',
        ]);
    }

    public function store(Request $request)
    {
        $product = new VehicleProduct($this->validatedProduct($request));
        $product->created_by = $request->user()->id;
        $this->saveProduct($product);

        return redirect()->route('fleet-products.edit', $product)->with('status', 'Prodotto creato. Ora puoi associare le auto della flotta.');
    }

    public function edit(Request $request, VehicleProduct $product)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'members_q' => ['nullable', 'string', 'max:120'],
        ]);

        return view('fleet-products.edit', [
            'product' => $product,
            'members' => $this->filterVehicles($product->vehicles()->withTrashed()->getQuery(), $filters['members_q'] ?? '')
                ->orderBy('plate')->orderBy('id')->paginate(25, ['*'], 'members_page')->withQueryString(),
            'candidates' => $this->filterVehicles(Vehicle::whereNull('vehicle_product_id'), $filters['q'] ?? '')
                ->orderBy('make')->orderBy('model')->orderBy('plate')->orderBy('id')->paginate(25, ['*'], 'candidates_page')->withQueryString(),
            'query' => $filters['q'] ?? '', 'membersQuery' => $filters['members_q'] ?? '',
        ]);
    }

    public function update(Request $request, VehicleProduct $product)
    {
        $product->fill($this->validatedProduct($request, $product));
        $this->saveProduct($product);

        return redirect()->route('fleet-products.edit', $product)->with('status', 'Prodotto aggiornato.');
    }

    public function attach(Request $request, VehicleProduct $product)
    {
        $data = $request->validate([
            'vehicle_ids' => ['required', 'array', 'min:1', 'max:200'],
            'vehicle_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ], ['vehicle_ids.required' => 'Seleziona almeno un’auto da associare.']);
        $ids = collect($data['vehicle_ids'])->map(fn ($id) => (int) $id)->sort()->values();

        $added = DB::transaction(function () use ($product, $ids) {
            VehicleProduct::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $vehicles = Vehicle::whereKey($ids->all())->orderBy('id')->lockForUpdate()->get();
            if ($vehicles->count() !== $ids->count()) {
                throw ValidationException::withMessages(['vehicle_ids' => 'Una delle auto selezionate non è più disponibile nella flotta. Aggiorna l’elenco.']);
            }
            if ($vehicles->contains(fn (Vehicle $vehicle) => $vehicle->vehicle_product_id !== null
                && (int) $vehicle->vehicle_product_id !== (int) $product->id)) {
                throw ValidationException::withMessages(['vehicle_ids' => 'Una delle auto appartiene già a un altro prodotto. Rimuovila da quel prodotto prima di associarla qui.']);
            }

            $added = 0;
            foreach ($vehicles as $vehicle) {
                if ($vehicle->vehicle_product_id === null) {
                    $vehicle->vehicle_product_id = $product->id;
                    $vehicle->save();
                    $added++;
                }
            }
            return $added;
        }, 3);

        return redirect()->route('fleet-products.edit', $product)
            ->with('status', $added ? 'Auto associate al prodotto: '.$added.'.' : 'Le auto selezionate erano già associate a questo prodotto.');
    }

    public function detach(VehicleProduct $product, int $vehicle)
    {
        DB::transaction(function () use ($product, $vehicle) {
            VehicleProduct::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $member = Vehicle::withTrashed()->whereKey($vehicle)->lockForUpdate()->firstOrFail();
            abort_unless((int) $member->vehicle_product_id === (int) $product->id, 404);
            $member->vehicle_product_id = null;
            $member->save();
        }, 3);

        return redirect()->route('fleet-products.edit', $product)->with('status', 'Associazione rimossa. L’auto resta nella flotta.');
    }

    private function filterVehicles(Builder $query, string $search): Builder
    {
        foreach (preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $query->where(fn (Builder $part) => $part->where('plate', 'like', '%'.$word.'%')
                ->orWhere('make', 'like', '%'.$word.'%')->orWhere('model', 'like', '%'.$word.'%'));
        }
        return $query;
    }

    private function validatedProduct(Request $request, ?VehicleProduct $product = null): array
    {
        $name = $request->input('name');
        if (is_string($name)) $request->merge(['name' => Str::squish($name)]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        validator(['name' => mb_strtolower($data['name'])], [
            'name' => [Rule::unique('vehicle_products', 'name_key')->ignore($product?->id)],
        ], ['name.unique' => 'Esiste già un prodotto con questo nome.'])->validate();

        return $data;
    }

    private function saveProduct(VehicleProduct $product): void
    {
        try {
            $product->save();
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['name' => 'Esiste già un prodotto con questo nome.']);
        }
    }
}
