<?php

namespace App\Console\Commands;

use App\Domain\Fees\AdminFeeResolver;
use App\Models\{Rental, RentalCharge};
use App\Services\Rentals\RentalPaymentService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CorrectRentalBasePayment extends Command
{
    protected $signature = 'rentals:correct-base-payment
        {rental : ID interno del noleggio, diverso dal numero contratto}
        {payment : ID del pagamento registrato come Altro}
        {--expected-amount= : Importo da verificare, con punto decimale}
        {--apply : Applica la riclassificazione dopo aver controllato il riepilogo}';

    protected $description = 'Mostra o corregge un singolo saldo registrato come Altro; aggiorna la commissione del noleggio chiuso.';

    public function handle(RentalPaymentService $payments, AdminFeeResolver $fees): int
    {
        $expected = (string) $this->option('expected-amount');
        if (!ctype_digit((string) $this->argument('rental')) || !ctype_digit((string) $this->argument('payment'))
            || !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $expected) || (float) $expected <= 0) {
            $this->error('Specificare gli ID numerici e --expected-amount con un importo positivo, ad esempio 65.00.');
            return self::FAILURE;
        }

        try {
            return DB::transaction(function () use ($expected, $payments, $fees) {
                $rental = Rental::query()->lockForUpdate()->findOrFail($this->argument('rental'));
                $payment = $rental->charges()->paid()->lockForUpdate()->findOrFail($this->argument('payment'));
                if ($payment->amount !== number_format((float) $expected, 2, '.', '')) {
                    throw ValidationException::withMessages(['amount' => 'L\'importo registrato non coincide con quello atteso. Nessuna modifica.']);
                }
                if (!$rental->organization?->isRenter() || !$rental->assignment_id) {
                    throw ValidationException::withMessages(['rental' => 'Il noleggio non appartiene a un renter con veicolo assegnato. Nessuna modifica.']);
                }
                if ($payment->kind === RentalCharge::KIND_BASE && $payment->is_commissionable) {
                    $this->info('Pagamento già classificato come quota base commissionabile. Nessuna modifica.');
                    return self::SUCCESS;
                }
                if ($payment->kind !== RentalCharge::KIND_OTHER || $payment->is_commissionable) {
                    throw ValidationException::withMessages(['kind' => 'La correzione ammette soltanto un pagamento Altro non commissionabile. Nessuna modifica.']);
                }
                $calculation = $fees->calculateForRental($rental);
                if ($rental->closed_at && $calculation['percent'] === null) {
                    throw ValidationException::withMessages(['fee' => 'Manca la percentuale salvata alla chiusura: verificare il contratto prima di correggerlo.']);
                }
                $newBase = round($calculation['commissionable_total'] + (float) $payment->amount, 2);
                $newFee = round($newBase * (($calculation['percent'] ?? 0) / 100), 2);
                $money = fn ($value) => number_format((float) $value, 2, ',', '.') . ' EUR';
                $this->table(['Dato', 'Valore'], [
                    ['Noleggio (ID interno)', $rental->id],
                    ['Numero contratto', $rental->number_id ?? 'Non disponibile'],
                    ['Organizzazione', $rental->organization->name],
                    ['Targa', $rental->vehicle?->plate ?? 'Non disponibile'],
                    ['Pagamento', $payment->id],
                    ['Registrato il', $payment->payment_recorded_at?->format('d/m/Y H:i') ?? 'Non disponibile'],
                    ['Importo', $money($payment->amount)],
                    ['Modifica', 'Altro escluso -> Quota base / saldo commissionabile'],
                    ['Nuova base commissioni', $money($newBase)],
                    ['Percentuale', $calculation['percent'] ?? 'Non definita'],
                    ['Commissione prima', $money($rental->closed_at ? $rental->admin_fee_amount : $calculation['amount'])],
                    ['Commissione dopo', $money($newFee)],
                ]);
                if (!$this->option('apply')) {
                    $this->info('Solo verifica: nessun pagamento modificato. Per applicare, ripetere con --apply.');
                    return self::SUCCESS;
                }

                $oldFee = $rental->admin_fee_amount;
                $payment->forceFill(['kind' => RentalCharge::KIND_BASE, 'is_commissionable' => true])->save();
                $payments->refreshClosedCommission($rental);
                activity('rental_payments')->performedOn($payment)->event('payment_reclassified')
                    ->withProperties(['rental_id' => $rental->id, 'source' => 'console',
                        'old' => ['kind' => RentalCharge::KIND_OTHER, 'is_commissionable' => false, 'admin_fee_amount' => $oldFee],
                        'attributes' => ['kind' => RentalCharge::KIND_BASE, 'is_commissionable' => true, 'admin_fee_amount' => $rental->admin_fee_amount],
                    ])->log('Saldo riclassificato da Altro a quota base tramite comando di manutenzione.');
                $this->info('Pagamento riclassificato. Importo, data, metodo e autore del pagamento sono conservati.');
                return self::SUCCESS;
            }, 3);
        } catch (ModelNotFoundException) {
            $this->error('Noleggio o pagamento non trovato, eliminato, non registrato oppure non associato al noleggio indicato.');
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->implode(' '));
        }
        return self::FAILURE;
    }
}
