<?php

namespace App\Http\Controllers;

use App\Models\{AmdRentOption, Organization, Vehicle, VehiclePricelist};
use App\Support\AmdRentAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\{Rule, ValidationException};

class AmdRentOptionsController extends Controller
{
    public function index(Request $request)
    {
        AmdRentAccess::admin($request->user());
        $data = $request->validate(['organization_id' => ['nullable', 'integer', 'min:1']]);
        $organizations = Organization::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $organization = isset($data['organization_id']) ? $organizations->find($data['organization_id']) : null;
        $options = $organization ? AmdRentOption::where('organization_id', $organization->id)
            ->with(['vehicle', 'product'])->orderBy('kind')->orderBy('name')->orderBy('id')->paginate(25)->withQueryString() : null;

        return view('amd-rent.options.index', compact('organizations', 'organization', 'options'));
    }

    public function create(Request $request, int $organization, string $kind)
    {
        AmdRentAccess::admin($request->user());
        $organization = Organization::where('is_active', true)->findOrFail($organization);
        $option = new AmdRentOption(['kind' => $kind, 'organization_id' => $organization->id]);

        return $this->form($organization, $option);
    }

    public function edit(Request $request, int $option)
    {
        AmdRentAccess::admin($request->user());
        $option = AmdRentOption::with('organization')->findOrFail($option);
        abort_unless($option->organization?->is_active, 404);

        return $this->form($option->organization, $option);
    }

    public function store(Request $request, int $organization, string $kind)
    {
        AmdRentAccess::admin($request->user());

        return $this->save($request, $organization, $kind);
    }

    public function update(Request $request, int $option)
    {
        AmdRentAccess::admin($request->user());
        $option = AmdRentOption::findOrFail($option);

        return $this->save($request, $option->organization_id, $option->kind, $option->id);
    }

    private function form(Organization $organization, AmdRentOption $option)
    {
        $vehicles = $this->eligibleVehicles($organization->id);
        $targets = $this->targets($vehicles);
        $targets += [$option->scopeKey() => 'Destinazione non più disponibile'];
        $previewVehicles = $option->kind === 'service' ? [] : $vehicles->map(fn (Vehicle $vehicle) => [
            'id' => (string) $vehicle->id,
            'group' => $vehicle->vehicle_product_id ? 'product:'.$vehicle->vehicle_product_id : null,
            'label' => $targets['vehicle:'.$vehicle->id],
            'franchises' => array_map(
                fn (string $column) => $vehicle->{$column} === null ? null : (string) $vehicle->{$column},
                AmdRentOption::VEHICLE_FRANCHISES,
            ),
        ])->all();

        return view('amd-rent.options.form', compact('organization', 'option', 'targets', 'previewVehicles'));
    }

    private function eligibleVehicles(int $organization): Collection
    {
        $pricelists = VehiclePricelist::where('renter_org_id', $organization)->whereIn('status', ['active', 'draft'])->select('vehicle_id');

        return Vehicle::whereIn('id', $pricelists)->where('is_active', true)
            ->with('product:id,name')->orderBy('make')->orderBy('model')->orderBy('plate')
            ->get(['id', 'vehicle_product_id', 'make', 'model', 'plate', ...array_values(AmdRentOption::VEHICLE_FRANCHISES)]);
    }

    private function targets(Collection $vehicles): array
    {
        $targets = ['all' => 'Tutte le auto di questo noleggiatore'];
        foreach ($vehicles->pluck('product')->filter()->unique('id')->sortBy('name') as $product) {
            $targets['product:'.$product->id] = 'Gruppo: '.$product->name;
        }
        foreach ($vehicles as $vehicle) {
            $targets['vehicle:'.$vehicle->id] = trim($vehicle->make.' '.$vehicle->model).' — '.$vehicle->plate;
        }

        return $targets;
    }

    private function save(Request $request, int $organizationId, string $kind, ?int $id = null)
    {
        $isStandard = $kind === 'standard';
        $isPlus = $kind === 'plus';
        $published = $request->boolean('published');
        $policy = $isPlus && $request->input('protection_type') === 'policy';
        $usesPriceMultiplier = $isPlus && $request->input('price_unit') === 'multiplier';
        $multiplierRules = ['nullable', 'numeric', 'max:'.AmdRentOption::MAX_MULTIPLIER, 'decimal:0,4'];
        $amountRules = ['nullable', 'numeric', 'min:0', 'max:100000', 'decimal:0,2'];
        $rules = [
            'scope' => ['required', 'string', 'max:30'],
            'published' => ['nullable', 'boolean'],
            'revision' => [Rule::requiredIf($id !== null), 'integer', 'min:1'],
            'description' => [Rule::requiredIf($isPlus && $published), 'nullable', 'string', 'max:2400'],
            'exclusions' => [Rule::requiredIf($isPlus && $published), 'nullable', 'string', 'max:2400'],
            'terms_url' => [Rule::requiredIf($policy && $published), 'nullable', 'url:https', 'max:500'],
            'coverage' => ['nullable', 'array:'.implode(',', array_keys(AmdRentOption::COVERAGES))],
            'coverage.*' => ['array:included,franchise,multiplier'],
            'coverage.*.included' => ['nullable', 'boolean'],
            'coverage.*.franchise' => $amountRules,
            'coverage.*.multiplier' => ['min:0', ...$multiplierRules],
        ];
        if (!$isStandard) {
            $rules += [
                'price' => [Rule::excludeIf($usesPriceMultiplier), Rule::requiredIf($published && !$usesPriceMultiplier), ...$amountRules],
                'price_unit' => [Rule::requiredIf($published), 'nullable', Rule::in($isPlus ? ['day', 'rental', 'multiplier'] : ['day', 'rental'])],
                'depositable' => ['nullable', 'boolean'],
                'commissionable' => ['nullable', 'boolean'],
            ];
        }
        $rules += $isPlus ? [
            'price_multiplier' => [Rule::excludeIf(!$usesPriceMultiplier), Rule::requiredIf($published && $usesPriceMultiplier), 'min:1', ...$multiplierRules],
            'protection_type' => [Rule::requiredIf($published), 'nullable', Rule::in(['reduction', 'policy'])],
            'provider' => [Rule::requiredIf($policy && $published), 'nullable', 'string', 'max:150'],
        ] : [];
        $rules += $kind === 'service' ? [
            'name' => ['required', 'string', 'max:100'],
            'covers_second_driver' => ['nullable', 'boolean'],
                'is_available' => ['nullable', 'boolean'],
        ] : [];

        $data = $request->validate($rules, [], [
            'scope' => 'destinatari', 'description' => 'descrizione', 'exclusions' => 'esclusioni',
            'terms_url' => 'link alle condizioni', 'coverage.*.franchise' => 'franchigia',
            'price_multiplier' => 'moltiplicatore della tariffa', 'coverage.*.multiplier' => 'moltiplicatore della franchigia',
            'price' => 'prezzo', 'price_unit' => 'modalità di prezzo', 'protection_type' => 'tipo di protezione',
            'provider' => 'fornitore della polizza', 'name' => 'nome del servizio',
        ]);

        [$coverage, $franchiseMultipliers] = $this->coverage($data['coverage'] ?? [], $kind, $data['protection_type'] ?? null, $published);
        $option = DB::transaction(function () use ($organizationId, $kind, $id, $data, $coverage, $franchiseMultipliers, $published) {
            /** Checkout uses the same organization lock when validating the accepted prices. */
            Organization::where('is_active', true)->lockForUpdate()->findOrFail($organizationId);
            $option = $id ? AmdRentOption::where('organization_id', $organizationId)->lockForUpdate()->findOrFail($id)
                : new AmdRentOption(['organization_id' => $organizationId, 'kind' => $kind]);
            abort_if($id && $option->revision !== (int) $data['revision'], 409, 'La configurazione è cambiata. Riapri la scheda.');
            if (!array_key_exists($data['scope'], $this->targets($this->eligibleVehicles($organizationId)))
                && (!$id || $published || $data['scope'] !== $option->scopeKey())) {
                throw ValidationException::withMessages(['scope' => 'Scegli un’auto o un gruppo con un listino di questo noleggiatore.']);
            }

            [$scope, $target] = explode(':', $data['scope'], 2) + [1 => null];
            $vehicle = $scope === 'vehicle' ? (int) $target : null;
            $product = $scope === 'product' ? (int) $target : null;
            $driver = (bool) ($data['covers_second_driver'] ?? false);
            if ($kind !== 'service' || $driver) {
                $duplicate = AmdRentOption::where('organization_id', $organizationId)->where('kind', $kind)
                    ->where('vehicle_id', $vehicle)->where('vehicle_product_id', $product)
                    ->when($id, fn ($query) => $query->whereKeyNot($id))
                    ->when($driver, fn ($query) => $query->where('covers_second_driver', true))->exists();
                if ($duplicate) {
                    throw ValidationException::withMessages(['scope' => 'Esiste già una configurazione di questo tipo per gli stessi destinatari. Aprila per modificarla.']);
                }
            }

            $option->fill([
                'vehicle_id' => $vehicle,
                'vehicle_product_id' => $product,
                'name' => $data['name'] ?? AmdRentOption::KINDS[$kind],
                'description' => $data['description'] ?? null,
                'exclusions' => $data['exclusions'] ?? null,
                'coverage' => $coverage ?: null,
                'franchise_multipliers' => $franchiseMultipliers ?: null,
                'protection_type' => $data['protection_type'] ?? null,
                'provider' => ($data['protection_type'] ?? null) === 'policy' ? ($data['provider'] ?? null) : null,
                'terms_url' => $data['terms_url'] ?? null,
                'price_cents' => isset($data['price']) ? (int) round($data['price'] * 100) : null,
                'price_multiplier_bps' => isset($data['price_multiplier'])
                    ? (int) round($data['price_multiplier'] * AmdRentOption::MULTIPLIER_SCALE) : null,
                'price_unit' => $data['price_unit'] ?? null,
                'depositable' => (bool) ($data['depositable'] ?? false),
                'commissionable' => (bool) ($data['commissionable'] ?? false),
                'covers_second_driver' => $driver,
                'is_available' => $kind !== 'service' || (bool) ($data['is_available'] ?? true),
                'published' => $published,
                'revision' => ($option->revision ?? 0) + 1,
            ])->save();

            return $option;
        }, 3);

        return redirect()->route('amd-rent.options.index', ['organization_id' => $option->organization_id])
            ->with('status', $option->published ? 'Configurazione pubblicata.' : 'Configurazione salvata in bozza.');
    }

    private function coverage(array $input, string $kind, ?string $type, bool $published): array
    {
        if ($kind === 'service' || $type === 'policy') {
            return [[], []];
        }

        $coverage = [];
        $multipliers = [];
        foreach (AmdRentOption::COVERAGES as $key => $label) {
            if (($key !== 'rca' || $kind !== 'standard') && empty($input[$key]['included'])) {
                continue;
            }
            if ($key === 'assistenza') {
                $coverage[$key] = null;
                continue;
            }
            $value = $input[$key]['franchise'] ?? null;
            $multiplier = $input[$key]['multiplier'] ?? null;
            if ($value !== null && $multiplier !== null) {
                throw ValidationException::withMessages(['coverage.'.$key.'.multiplier' => 'Scegli un importo fisso oppure un moltiplicatore per '.$label.'.']);
            }
            if ($kind === 'plus' && $published && $value === null && $multiplier === null) {
                throw ValidationException::withMessages(['coverage.'.$key.'.franchise' => 'Indica un importo o un moltiplicatore con AMD Plus per '.$label.'.']);
            }
            $coverage[$key] = $value === null ? null : (int) round($value * 100);
            if ($multiplier !== null) {
                $multipliers[$key] = (int) round($multiplier * AmdRentOption::MULTIPLIER_SCALE);
            }
        }
        if ($kind === 'plus' && $published && $coverage === []) {
            throw ValidationException::withMessages(['coverage' => 'Indica almeno una copertura o franchigia che cambia con AMD Plus.']);
        }

        return [$coverage, $multipliers];
    }
}
