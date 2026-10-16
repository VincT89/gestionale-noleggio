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
        {--without-photos : Copia senza fotografie reali; restano le immagini di riferimento disponibili}
        {--apply : Crea le copie dopo aver controllato l\'anteprima}
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
            $plan = $fleet->preview((int) $id, $choices, $withoutPhotos);
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
                ['Fotografie originali da copiare', array_sum(array_map(fn ($v) => count($v['photos']), $plan['vehicles']))],
            ]);
            $this->table(['Auto origine', 'Targa demo', 'Marca/modello', 'Listino origine', 'EUR/giorno', 'Stagioni', 'Fasce'],
                array_map(fn ($v) => [$v['source_id'], $v['plate'], $v['attributes']['make'].' '.$v['attributes']['model'],
                    $v['price']['source_id'] ?? 'DA SCEGLIERE',
                    $v['price'] ? number_format($v['price']['attributes']['base_daily_cents'] / 100, 2, ',', '.') : '-',
                    count($v['price']['seasons'] ?? []), count($v['price']['tiers'] ?? [])], $plan['vehicles']));
            $this->table(['Luogo demo', 'Città', 'Consegna a domicilio'], array_map(fn ($d) => [
                $d['place']['name'], $d['place']['city'] ?: 'Non indicata',
                $d['attributes']['custom_delivery_enabled'] ? 'Configurazione copiata' : 'Disabilitata',
            ], array_values($plan['deliveries'])));
            $this->warn('Le copie compariranno nei conteggi dell\'admin. Le auto originali e i loro impegni restano invariati.');
            $this->warn('Proteggere il portale durante la dimostrazione: questa procedura non disabilita le prenotazioni.');
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
            $result = $fleet->apply((int) $id, $choices, $withoutPhotos, (string) $this->option('confirm'));
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
}
