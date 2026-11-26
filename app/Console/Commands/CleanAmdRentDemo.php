<?php

namespace App\Console\Commands;

use App\Services\AmdRent\DemoCleanup;
use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

class CleanAmdRentDemo extends Command
{
    protected $signature = 'amd-rent:demo-cleanup
        {renter : ID numerico del noleggiatore DEMO da verificare}
        {--deactivate : Prepara solo la disattivazione del profilo, conservando dati e fotografie}
        {--apply : Applica esclusivamente l’anteprima confermata}
        {--confirm= : Impronta completa dell’anteprima, valida per questo sito e database}';

    protected $description = 'Mostra la pulizia della sola demo tracciata. Senza --apply non modifica dati o file.';

    public function handle(DemoCleanup $cleanup): int
    {
        try {
            $id = (string) $this->argument('renter');
            if (!preg_match('/^[1-9][0-9]{0,9}$/D', $id)) throw new RuntimeException('Indicare l’ID numerico esplicito del noleggiatore DEMO.');
            $deactivate = (bool) $this->option('deactivate');
            $plan = $cleanup->preview((int) $id, $deactivate);
            $this->table(['Destinazione', 'Valore'], [
                ['Noleggiatore DEMO', $id.' — '.$this->text($plan['renter']['name'])], ['Sito', $this->text($plan['application'])],
                ['Database', $this->text($plan['database']['database'])], ['Operazione proposta', $deactivate ? 'Solo disattivazione' : 'Rimozione copie demo e foto verificate'],
                ['Profilo e utenti', 'Conservati; profilo disattivato all’applicazione'],
            ]);
            $labels = ['vehicles' => 'Auto demo', 'vehicle_pricelists' => 'Listini', 'vehicle_pricelist_seasons' => 'Stagioni',
                'vehicle_pricelist_tiers' => 'Fasce di durata', 'vehicle_assignments' => 'Assegnazioni', 'vehicle_states' => 'Stati iniziali',
                'locations' => 'Sedi demo', 'public_delivery_locations' => 'Luoghi serviti', 'public_pickup_places' => 'Punti di ritiro demo',
                'vehicle_products' => 'Segmenti demo', 'amd_rent_options' => 'Protezioni e accessori', 'public_rental_offers' => 'Offerte demo', 'media' => 'Foto demo'];
            $this->table(['Dati individuati', 'Numero', 'ID esatti'], array_map(fn ($table) => [
                $labels[$table] ?? $table, count($plan['ids'][$table]), implode(', ', $plan['ids'][$table]) ?: '—',
            ], array_keys($plan['ids'])));
            foreach ($plan['photos'] as $photo) {
                foreach ($photo['manifest']['files'] as $file) $this->line('Foto #'.$photo['id'].' — '.$this->text($file['disk'].':'.$file['path']));
            }
            foreach ($plan['warnings'] as $warning) $this->warn($warning);
            foreach ($plan['errors'] as $error) $this->error($error);
            if ($plan['errors']) {
                $this->error('Operazione bloccata. Nessun dato o file modificato.');
                return self::FAILURE;
            }
            if ($plan['empty']) {
                $this->info($deactivate ? 'Profilo DEMO già disattivato. Nessuna modifica.' : 'Demo già rimossa e profilo disattivato. Nessuna modifica.');
                return self::SUCCESS;
            }
            if (!$this->option('apply')) {
                $this->info('Solo anteprima: nessun dato o file modificato.');
                $this->line('Impronta: '.$plan['fingerprint']);
                $this->line('Conservare questo riepilogo. Dopo il via libera e un backup recente di database e storage, ripetere gli stessi argomenti con --apply --confirm=IMPRONTA.');
                return self::SUCCESS;
            }
            $cleanup->apply((int) $id, (string) $this->option('confirm'), $deactivate);
            $this->info($deactivate ? 'Profilo DEMO disattivato. Dati e fotografie conservati.'
                : 'Copie demo e foto verificate rimosse. Profilo DEMO disattivato; utenti conservati.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            /** Unexpected SQL and storage exceptions may contain sensitive paths or bindings. */
            $this->error(get_class($exception) === RuntimeException::class ? $exception->getMessage()
                : 'Procedura interrotta ('.class_basename($exception).'). Verificare schema e permessi prima di riprovare.');
            return self::FAILURE;
        }
    }

    private function text(?string $value): string
    {
        return OutputFormatter::escape(preg_replace('/[\x00-\x1f\x7f]/u', ' ', $value ?? '') ?? '');
    }
}
