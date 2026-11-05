<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\AmdRent\DemoFleet;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class PrepareAmdRentDemo extends Command
{
    protected $signature = 'amd-rent:demo-fleet
        {renter* : ID o nome completo univoco del nuovo noleggiatore DEMO}
        {--price=* : Scelta esplicita AUTO:LISTINO, ripetibile quando ci sono prezzi ambigui}
        {--demo-daily= : Euro/giorno dimostrativi solo per auto senza un listino attivo univoco}
        {--without-photos : Copia senza fotografie reali; restano le immagini di riferimento disponibili}
        {--available-photos : Copia solo le foto disponibili, segnalando quelle mancanti o non copiabili}
        {--enable-demo-map : Configura la demo già creata per tutti gli indirizzi riconosciuti, senza prenotazioni reali}
        {--update-demo-areas : Completa la demo esistente con Aeroporto, Porto e Stazione di Bari, senza ricopiare la flotta}
        {--apply : Applica la creazione o l\'aggiornamento dopo aver controllato l\'anteprima}
        {--confirm= : Impronta completa mostrata dall\'anteprima da applicare}';

    protected $description = 'Prepara una copia dimostrativa della flotta attiva, con listini e luoghi. Senza --apply non scrive nulla.';

    public function handle(DemoFleet $fleet): int
    {
        try {
            /** Accept the same name when a hosting panel passes its words as separate arguments. */
            $id = trim(implode(' ', (array) $this->argument('renter')));
            if (strlen($id) >= 2 && in_array($id[0], ['"', "'"], true) && $id[0] === substr($id, -1)) {
                $id = substr($id, 1, -1);
            }
            if (!ctype_digit($id)) {
                $matches = Organization::query()->where('name', $id)->where('type', 'renter')
                    ->where('is_active', true)->limit(2)->pluck('id');
                if ($matches->count() !== 1) {
                    throw new RuntimeException('Nome assente o ambiguo: indicare il nome completo univoco oppure l\'ID del noleggiatore DEMO.');
                }
                $id = (string) $matches->sole();
            }
            if (!ctype_digit($id) || (int) $id < 1 || (string) (int) $id !== $id) {
                throw new RuntimeException('Indicare l\'ID numerico del noleggiatore DEMO.');
            }
            if ($this->option('update-demo-areas')) {
                if ($this->option('enable-demo-map') || $this->option('price') || $this->option('demo-daily') !== null
                    || $this->option('without-photos') || $this->option('available-photos')) {
                    throw new RuntimeException('Usare --update-demo-areas da solo: auto, foto, listini e copertura esistente sono conservati.');
                }
                return $this->updateAreas((int) $id);
            }
            if ($this->option('enable-demo-map')) {
                $map = app(\App\Services\AmdRent\DemoMap::class);
                $plan = $map->preview((int) $id);
                $this->table(['Configurazione demo', 'Valore'], [
                    ['Noleggiatore', $id.' - '.$plan['renter']['name']], ['Database', $plan['database']],
                    ['Host', $plan['host'] ?? 'locale'], ['Luoghi demo', count($plan['deliveries'])],
                    ['Copertura', 'Qualsiasi indirizzo riconosciuto dalla mappa'], ['Consegna e ritiro', '0 EUR, dimostrativi'],
                    ['Prenotazioni', 'Disabilitate per le copie demo'],
                ]);
                if ($plan['already_configured']) {
                    $this->info('Copertura demo già configurata. Nessuna modifica.');
                } elseif (!$this->option('apply')) {
                    $this->info('Solo anteprima: nessun dato o file modificato.');
                    $this->line('Impronta: '.$plan['fingerprint']);
                    $this->line('Ripetere gli stessi argomenti aggiungendo --apply --confirm='.$plan['fingerprint']);
                } else {
                    $count = $map->apply((int) $id, (string) $this->option('confirm'));
                    $this->info('Copertura simulata configurata su '.$count.' luoghi demo. Nessuna auto duplicata.');
                }
                return self::SUCCESS;
            }
            $choices = [];
            foreach ($this->option('price') as $choice) {
                if (!preg_match('/^([1-9][0-9]*):([1-9][0-9]*)$/', $choice, $match)
                    || isset($choices[(int) $match[1]])) {
                    throw new RuntimeException('Ogni --price deve avere formato AUTO:LISTINO, senza ripetere la stessa auto.');
                }
                $choices[(int) $match[1]] = (int) $match[2];
            }
            ksort($choices);
            $withoutPhotos = (bool) $this->option('without-photos');
            $availablePhotos = (bool) $this->option('available-photos');
            $demoDailyCents = null;
            if ($this->option('demo-daily') !== null) {
                $amount = (string) $this->option('demo-daily');
                if (!preg_match('/^([0-9]{1,6})(?:[.,]([0-9]{1,2}))?$/', $amount, $match)) {
                    throw new RuntimeException('Indicare --demo-daily in euro, per esempio --demo-daily=50 oppure --demo-daily=50.50.');
                }
                $demoDailyCents = (int) $match[1] * 100 + (int) str_pad($match[2] ?? '', 2, '0');
            }
            $plan = $fleet->preview((int) $id, $choices, $withoutPhotos, $demoDailyCents, $availablePhotos);
            if ($plan['already_created']) {
                $this->warn('Copie demo già presenti: '.$plan['existing_vehicles'].'. Nessun dato aggiornato e nessun doppione creato.');
                $this->line('Questo comando crea una sola istantanea; non sincronizza o ripristina una demo già modificata.');
                return self::SUCCESS;
            }
            $this->table(['Destinazione', 'Valore'], [
                ['Noleggiatore', $id.' - '.$plan['renter']['name']],
                ['Database', $plan['database']['name']],
                ['Host', $plan['database']['host'] ?? 'locale'],
                ['Auto attive da copiare', count($plan['vehicles'])],
                ['Sedi da copiare', count($plan['locations'])],
                ['Luoghi serviti demo', count($plan['deliveries'])],
                ['Categorie demo', count($plan['products'])],
                ['Listini con tariffa dimostrativa', count(array_filter($plan['vehicles'], fn ($v) => $v['price']['is_demo'] ?? false))],
                ['Fotografie originali da copiare', array_sum(array_map(fn ($v) => count($v['photos']), $plan['vehicles']))],
            ]);
            $this->table(['Auto origine', 'Targa demo', 'Marca/modello', 'Tipo/categoria', 'Listino origine', 'EUR/giorno', 'Stagioni', 'Fasce'],
                array_map(fn ($v) => [$v['source_id'], $v['plate'], $v['attributes']['make'].' '.$v['attributes']['model'],
                    $plan['products'][$v['product_key']]['name'] ?? 'NON CONFIGURATA',
                    ($v['price']['is_demo'] ?? false) ? 'TARIFFA DEMO' : ($v['price']['source_id'] ?? 'DA SCEGLIERE'),
                    $v['price'] ? number_format($v['price']['attributes']['base_daily_cents'] / 100, 2, ',', '.') : '-',
                    count($v['price']['seasons'] ?? []), count($v['price']['tiers'] ?? [])], $plan['vehicles']));
            $this->table(['Luogo demo', 'Città', 'Consegna a domicilio'], array_map(fn ($d) => [
                $d['place']['name'], $d['place']['city'] ?: 'Non indicata',
                $d['attributes']['custom_delivery_enabled'] ? 'Configurazione copiata' : 'Disabilitata',
            ], array_values($plan['deliveries'])));
            $this->warn('Le copie compariranno nei conteggi dell\'admin. Le auto originali e i loro impegni restano invariati.');
            $this->warn('Le copie sono dimostrative: prenotazioni, richieste di consegna e pagamenti dal portale sono disabilitati.');
            foreach ($plan['warnings'] as $warning) {
                $this->warn($warning);
            }
            foreach ($plan['errors'] as $error) {
                $this->error($error);
            }
            if ($plan['errors']) {
                $this->error('Nessuna modifica. Risolvere tutte le voci prima di creare la demo.');
                return self::FAILURE;
            }
            if (!$this->option('apply')) {
                $this->info('Solo anteprima: nessun dato o file creato.');
                $this->line('Impronta: '.$plan['fingerprint']);
                $this->line('Dopo il backup e la verifica, ripetere gli stessi argomenti aggiungendo --apply --confirm='.$plan['fingerprint']);
                return self::SUCCESS;
            }
            $result = $fleet->apply((int) $id, $choices, $withoutPhotos, (string) $this->option('confirm'), $demoDailyCents, $availablePhotos);
            $this->info('Demo creata: '.count($result['vehicles']).' auto, '.$result['pricelists'].' listini, '
                .count($result['locations']).' sedi, '.$result['deliveries'].' luoghi serviti e '.$result['photos'].' foto.');
            $this->line('Assegnazioni aperte da questo momento, solo sulle copie. Nessun cliente, noleggio, pagamento o documento copiato.');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            /** Database and filesystem exceptions may contain sensitive bindings or paths. */
            $this->error(get_class($exception) === RuntimeException::class ? $exception->getMessage()
                : 'Procedura interrotta ('.class_basename($exception).'). Controllare lo schema e i permessi prima di riprovare.');
        }

        return self::FAILURE;
    }

    private function updateAreas(int $renter): int
    {
        $groups = app(\App\Services\AmdRent\DemoPickupGroups::class);
        $plan = $groups->preview($renter);
        $this->table(['Aggiornamento demo', 'Valore'], [
            ['Noleggiatore', $renter.' - '.$plan['renter']['name']], ['Database', $plan['database']],
            ['Host', $plan['host'] ?? 'locale'], ['Auto, foto e listini', 'Conservati, senza nuove copie'],
            ['Ricerca per indirizzo', $plan['map_enabled'] ? 'Copertura simulata già abilitata, conservata' : 'Configurazione esistente conservata'],
            ['Prenotazioni', 'Disabilitate per le copie demo'],
        ]);
        $actions = ['keep' => 'Già coperta', 'create' => 'Aggiungere punto dimostrativo', 'enable' => 'Riattivare punto dimostrativo'];
        $this->table(['Area', 'Azione', 'Punto di incontro'], array_map(fn ($area) => [
            $area['label'], $actions[$area['action']], $area['place']['address_line'],
        ], $plan['areas']));
        if ($plan['already_configured']) {
            $this->info('Le tre aree di Bari sono già configurate per la demo. Nessuna modifica.');
        } elseif (!$this->option('apply')) {
            $this->info('Solo anteprima: nessun dato o file modificato.');
            $this->line('Impronta: '.$plan['fingerprint']);
            $this->line('Ripetere gli stessi argomenti aggiungendo --apply --confirm='.$plan['fingerprint']);
        } else {
            $count = $groups->apply($renter, (string) $this->option('confirm'));
            $this->info('Aree demo aggiornate: '.$count.'. Auto, foto e listini conservati.');
        }
        return self::SUCCESS;
    }
}
