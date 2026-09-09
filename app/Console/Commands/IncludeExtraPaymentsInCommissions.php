<?php

namespace App\Console\Commands;

use App\Domain\Fees\AdminFeeResolver;
use App\Models\{Rental, RentalCharge};
use App\Services\Rentals\RentalPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IncludeExtraPaymentsInCommissions extends Command
{
    protected $signature = 'rentals:include-extra-commissions
        {--apply : Include i pagamenti e aggiorna le commissioni chiuse}
        {--rental= : Limita il controllo a un ID interno di noleggio}';

    protected $description = 'Verifica o include Sovrapprezzi e Altro già registrati nella base commissioni.';

    public function handle(AdminFeeResolver $fees, RentalPaymentService $payments): int
    {
        if ($this->option('rental') !== null && !ctype_digit((string) $this->option('rental'))) {
            $this->error('Specificare un ID interno di noleggio numerico.');
            return self::FAILURE;
        }
        $eligible = fn ($q) => $q->whereIn('kind', [RentalCharge::KIND_SURCHARGE, RentalCharge::KIND_OTHER])
            ->where('is_commissionable', false);
        $query = Rental::whereNotNull('assignment_id')->whereHas('charges', $eligible)
            ->when($this->option('rental') !== null, fn ($q) => $q->whereKey($this->option('rental')));
        $rows = [];
        $changed = 0;
        $blocked = 0;
        foreach ($query->orderBy('id')->pluck('id') as $id) {
            DB::transaction(function () use ($id, $eligible, $fees, $payments, &$rows, &$changed, &$blocked) {
                $rental = Rental::lockForUpdate()->find($id);
                if (!$rental || !$rental->assignment_id) {
                    return;
                }
                $charges = $eligible($rental->charges())->lockForUpdate()->get();
                if ($charges->isEmpty()) {
                    return;
                }
                $calculation = $fees->calculateForRental($rental);
                $paid = round((float) $charges->where('payment_recorded', true)->sum('amount'), 2);
                if ($rental->closed_at && $calculation['percent'] === null && $paid != 0) {
                    $blocked++;
                    $rows[] = [$id, $charges->count(), $paid, 'Percentuale storica mancante', 'Da verificare'];
                    return;
                }
                $newFee = round(($calculation['commissionable_total'] + $paid) * (($calculation['percent'] ?? 0) / 100), 2);
                $oldFee = $rental->closed_at ? $rental->admin_fee_amount : $calculation['amount'];
                $rows[] = [$id, $charges->count(), number_format($paid, 2, '.', ''),
                    number_format((float) $oldFee, 2, '.', ''), number_format($newFee, 2, '.', '')];
                if (!$this->option('apply')) {
                    return;
                }
                $rental->charges()->whereKey($charges->modelKeys())->update(['is_commissionable' => true]);
                $payments->refreshClosedCommission($rental);
                activity('rental_payments')->performedOn($rental)->event('extra_payments_commissioned')
                    ->withProperties([
                        'source' => 'console', 'payment_ids' => $charges->modelKeys(),
                        'previous_admin_fee_amount' => $oldFee, 'new_admin_fee_amount' => $rental->admin_fee_amount,
                        'percent' => $calculation['percent'],
                    ])->log('Sovrapprezzi e Altro inclusi nelle commissioni con la nuova regola.');
                $changed += $charges->count();
            }, 3);
        }
        $this->table(['ID noleggio', 'Voci', 'Incassato da includere EUR', 'Commissione prima EUR', 'Commissione dopo EUR'], $rows);
        $this->info($this->option('apply')
            ? "Aggiornate {$changed} voci. Importi, tipi, date e metodi di pagamento conservati."
            : 'Solo verifica: nessun dato modificato. Usare --apply per applicare.');
        if ($blocked) {
            $this->error("{$blocked} contratti richiedono la verifica della percentuale storica; non sono stati modificati.");
        }
        return $blocked ? self::FAILURE : self::SUCCESS;
    }
}
