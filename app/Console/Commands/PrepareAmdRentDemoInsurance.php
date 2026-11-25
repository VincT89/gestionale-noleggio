<?php

namespace App\Console\Commands;

use App\Models\{AmdRentOption, Organization, Vehicle, VehiclePricelist};
use App\Services\AmdRent\{DemoFleet, DemoMap};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class PrepareAmdRentDemoInsurance extends Command
{
    protected $signature = 'amd-rent:demo-insurance {renter? : ID del noleggiatore DEMO da aggiornare} {--min-cars=3 : Numero minimo di auto demo, da 1 a 10} {--apply : Aggiunge le sole copie e protezioni demo mancanti}';

    protected $description = 'Conta e completa le auto demo, con Standard e AMD Plus modificabili in ERA. Senza --apply non modifica dati.';

    public function handle(): int
    {
        try {
            $id = $this->argument('renter');
            $minimum = (string) $this->option('min-cars');
            if (!ctype_digit($minimum) || (int) $minimum < 1 || (int) $minimum > 10) {
                throw new RuntimeException('Il numero minimo di auto demo deve essere compreso tra 1 e 10.');
            }
            $minimum = (int) $minimum;
            if ($id !== null && (!ctype_digit((string) $id) || (int) $id < 1)) {
                throw new RuntimeException('Indicare l’ID numerico del noleggiatore DEMO.');
            }
            if ($this->option('apply') && $id === null) {
                throw new RuntimeException('Per applicare le protezioni indica prima l’ID DEMO mostrato dal comando senza argomenti.');
            }
            $organizations = Organization::where('type', 'renter')->where('is_active', true)
                ->when($id !== null, fn ($query) => $query->whereKey((int) $id))->orderBy('id')->get()
                ->filter(fn ($organization) => preg_match('/^DEMO\b/iu', $organization->name));
            if ($organizations->isEmpty()) {
                throw new RuntimeException('Nessun noleggiatore DEMO attivo corrisponde alla richiesta.');
            }
            $rows = [];
            foreach ($organizations as $organization) {
                $prices = VehiclePricelist::where('renter_org_id', $organization->id)->with('vehicle')->get();
                $demo = $prices->filter(fn ($price) => $price->vehicle && DemoMap::isVehicle($price->vehicle, $organization->id));
                $rows[] = [$organization->id, $organization->name, $demo->pluck('vehicle_id')->unique()->count(),
                    $demo->where('status', 'active')->count(), $prices->count() - $demo->count()];
            }
            $this->table(['ID DEMO', 'Noleggiatore', 'Auto demo', 'Listini attivi', 'Listini non demo o da verificare'], $rows);
            $this->line('Obiettivo: almeno '.$minimum.' auto demo. Le eventuali nuove copie riprendono un’auto demo attiva, con targa distinta e tariffa dimostrativa di 100 EUR/giorno. Le auto esistenti non vengono modificate.');
            $this->line('Esempio: Standard RCA 1.500 EUR e furto 3.000 EUR; Plus RCA 500 EUR e furto 1.500 EUR. Tariffa auto con Plus: moltiplicatore 1,88.');
            if (!$this->option('apply')) {
                $this->info('Solo lettura. Per aggiungere i dati dimostrativi: amd-rent:demo-insurance ID --apply');
                return self::SUCCESS;
            }
            if (!Schema::hasTable('amd_rent_options') || !Schema::hasColumn('amd_rent_options', 'price_multiplier_bps')) {
                throw new RuntimeException('La migrazione delle protezioni non è completa. Verificarla prima di aggiornare la demo.');
            }
            $result = DB::transaction(function () use ($id, $minimum) {
                /** The same organization lock serializes this command with the options editor. */
                $organization = Organization::whereKey((int) $id)->lockForUpdate()->firstOrFail();
                if (!$organization->is_active || $organization->type !== 'renter' || !preg_match('/^DEMO\b/iu', $organization->name)) {
                    throw new RuntimeException('Il destinatario deve essere un noleggiatore DEMO attivo.');
                }
                $prices = VehiclePricelist::where('renter_org_id', $organization->id)->with('vehicle')->lockForUpdate()->get();
                if ($prices->isEmpty() || $prices->contains(fn ($price) => !$price->vehicle || !DemoMap::isVehicle($price->vehicle, $organization->id))) {
                    throw new RuntimeException('Operazione annullata: tutti i listini del destinatario devono appartenere a copie demo riconosciute.');
                }
                $addedCars = $this->completeCars($organization, $prices, $minimum);
                $created = 0;
                foreach ([
                    'standard' => ['name' => 'Protezione standard', 'coverage' => ['rca' => 150000, 'furto_incendio' => 300000]],
                    'plus' => ['name' => 'AMD Plus', 'coverage' => ['rca' => 50000, 'furto_incendio' => 150000],
                        'protection_type' => 'reduction', 'price_unit' => 'multiplier', 'price_multiplier_bps' => 18800,
                        'depositable' => true, 'commissionable' => true],
                ] as $kind => $values) {
                    /** Existing drafts and published rules are preserved, including manual edits. */
                    $option = AmdRentOption::firstOrCreate([
                        'organization_id' => $organization->id, 'kind' => $kind, 'vehicle_id' => null, 'vehicle_product_id' => null,
                    ], $values + ['published' => true,
                        'description' => 'Esempio dimostrativo di coperture e franchigie. Non è un’offerta assicurativa.',
                        'exclusions' => 'Condizioni simulate per la presentazione. Nessuna copertura reale viene attivata.']);
                    $created += (int) $option->wasRecentlyCreated;
                }
                return ['options' => $created, 'cars' => $addedCars];
            });
            $this->info('Auto demo aggiunte: '.$result['cars'].'. Configurazioni demo aggiunte: '.$result['options'].'. Configurazioni esistenti, auto originali e listini conservati. Prenotazioni e pagamenti demo restano disabilitati.');
            return self::SUCCESS;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }

    private function completeCars(Organization $organization, \Illuminate\Support\Collection $prices, int $minimum): int
    {
        $active = $prices->filter(fn ($price) => $price->status === 'active' && $price->vehicle->is_active);
        $count = $active->pluck('vehicle_id')->unique()->count();
        if ($count >= $minimum) return 0;
        $source = $active->sortBy('id')->first();
        if (!$source) throw new RuntimeException('Serve almeno un’auto demo attiva con listino attivo da usare come modello.');
        $now = now();
        for ($index = $count + 1; $index <= $minimum; $index++) {
            $marker = DemoFleet::marker($organization->id, 'vehicle', 'insurance-preview-'.$index);
            $plate = 'DEMO'.strtoupper(substr(hash('sha256', $marker), 0, 12));
            if (Vehicle::withTrashed()->where('plate', $plate)->exists()) {
                throw new RuntimeException('Una copia prevista esiste già o è archiviata. Nessuna auto esistente viene sovrascritta.');
            }
            /** Copy only the existing demo vehicle; original records, VIN and media are never reused as new identifiers. */
            $attributes = $source->vehicle->getAttributes();
            unset($attributes['id'], $attributes['deleted_at']);
            $attributes = array_replace($attributes, ['plate' => $plate, 'vin' => null, 'notes' => $marker,
                'insurance_rca_cents' => 50000, 'insurance_furto_cents' => 150000, 'created_at' => $now, 'updated_at' => $now]);
            $vehicleId = DB::table('vehicles')->insertGetId($attributes);
            DB::table('vehicle_assignments')->insert(['vehicle_id' => $vehicleId, 'renter_org_id' => $organization->id,
                'start_at' => $now, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('vehicle_states')->insert(['vehicle_id' => $vehicleId, 'state' => 'assigned',
                'started_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            $price = $source->getAttributes();
            unset($price['id'], $price['deleted_at']);
            $price = array_replace($price, ['vehicle_id' => $vehicleId, 'name' => 'DEMO - Tariffa dimostrativa',
                'base_daily_cents' => 10000, 'weekend_pct' => 0, 'version' => 1,
                'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            if (array_key_exists('notes', $price)) $price['notes'] = DemoFleet::marker($organization->id, 'price', 'insurance-preview-'.$index);
            DB::table('vehicle_pricelists')->insert($price);
        }
        return $minimum - $count;
    }
}
