<?php
namespace App\Services\AmdRent;

use App\Models\AmdRentEnquiry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryQuotes
{
    public function apply(array $car, AmdRentEnquiry $case, ?Collection $attempts = null): array
    {
        $context = $case->booking_context;
        if ($case->type !== 'delivery' || $case->status !== 'quoted' || !$case->quote_expires_at || $case->quote_expires_at->isPast()
            || (int) $case->organization_id !== $car['supplier_id'] || ($context['pricelist'] ?? null) !== $car['id']
            || ($context['period']['place_id'] ?? null) !== $car['place_id'] || !$car['custom_delivery_enabled']) {
            throw ValidationException::withMessages(['booking' => 'La proposta di consegna non è più valida. Richiedi una nuova conferma al noleggiatore.']);
        }
        app(DeliveryBookingRecovery::class)->assertNoBlockingPayments($case, attempts: $attempts);
        $fee = (int) $case->delivery_fee_cents;
        $return = !empty($context['return_address']) ? ['return_address' => $context['return_address']] : [];
        if ($return && !empty($context['return_destination'])) $return['return_destination'] = $context['return_destination'];
        $base = $car['rental_total_cents'] ?? $car['total_cents'];
        $result = array_replace($car, $return, ['rental_total_cents' => $base, 'total_cents' => $base + $fee,
            'delivery_fee_cents' => $fee, 'delivery_address' => $case->delivery_address,
            'delivery_request_id' => $case->id, 'delivery_revision' => $case->revision]);
        if (isset($context['car']['commission_bps'])) {
            $result['commission_bps'] = $result['delivery_commission_bps'] = (int) $context['car']['commission_bps'];
        } else {
            // Requests created before the total commission retain their previous split rule.
            $previousBps = $attempts ? $attempts->sortByDesc('id')->first()?->delivery_commission_bps
                : app(DeliveryBookingRecovery::class)->previousDeliveryCommissionBps($case);
            $bps = $previousBps ?? DB::table('amd_rent_settings')->where('id', 1)->value('delivery_commission_bps');
            if ($bps === null) throw ValidationException::withMessages(['booking' => 'Questa proposta precedente richiede una nuova conferma di AMD Rent prima del pagamento.']);
            unset($result['commission_bps']);
            $result['delivery_commission_bps'] = (int) $bps;
        }
        return $result;
    }
    public static function onlineDue(array $car): int
    {
        if (isset($car['commission_bps'])) return intdiv($car['total_cents'] * $car['commission_bps'] + 5000, 10000);
        $base = $car['rental_total_cents'] ?? $car['total_cents'];
        return intdiv($base * 2000 + 5000, 10000)
            + intdiv(($car['delivery_fee_cents'] ?? 0) * ($car['delivery_commission_bps'] ?? 0) + 5000, 10000);
    }
}
