<?php

namespace App\Domain\Rentals;

use App\Models\{Customer, PublicBooking, PublicRentalOffer, Rental, RentalContractSnapshot, VehicleAssignment};
use App\Services\Rentals\RentalNumberAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicBookingService
{
    public function __construct(private PublicVehicleSearch $search, private RentalNumberAllocator $numbers, private ReservationTransaction $transaction) {}

    public static function fingerprint(array $car): string
    {
        $fields = ['id', 'title', 'organization', 'location', 'city', 'address', 'description', 'total_cents',
            'days', 'deposit_cents', 'km_per_day', 'extra_km_cents', 'prices_include_vat'];
        return hash('sha256', json_encode(array_intersect_key($car, array_flip($fields)), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function reserve(array $intent, array $contact): PublicBooking
    {
        $hash = hash('sha256', $intent['nonce']);
        if ($existing = PublicBooking::where('request_hash', $hash)->first()) return $existing;
        $candidate = PublicRentalOffer::published()->findOrFail($intent['offer']);

        return $this->transaction->run($candidate->organization_id, [$candidate->vehicle_id], function () use ($candidate, $intent, $contact, $hash) {
            if ($existing = PublicBooking::where('request_hash', $hash)->lockForUpdate()->first()) return $existing;
            $offer = PublicRentalOffer::published()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($offer->vehicle_id !== $candidate->vehicle_id || $offer->organization_id !== $candidate->organization_id) {
                throw ValidationException::withMessages(['booking' => 'L’offerta è cambiata. Riapri il riepilogo prima di confermare.']);
            }
            $car = $this->search->search(PublicRentalOffer::published()->whereKey($offer->id), $intent['period'])->first();
            $this->assertQuote($intent, $car);

            // An unauthenticated visitor cannot claim or overwrite an existing customer by email.
            $customer = Customer::create($contact + [
                'organization_id' => $offer->organization_id, 'name' => trim($contact['first_name'].' '.$contact['last_name']),
            ]);
            $start = CarbonImmutable::parse($intent['period']['pickup_at'], config('app.timezone'));
            $end = CarbonImmutable::parse($intent['period']['return_at'], config('app.timezone'));
            $assignment = VehicleAssignment::where('vehicle_id', $offer->vehicle_id)->where('renter_org_id', $offer->organization_id)
                ->whereIn('status', ['active', 'scheduled'])->where('start_at', '<=', $start)
                ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>', $start))->latest('start_at')->first();
            $rental = $this->numbers->allocateAndCreate($offer->organization_id, null, fn (int $number) => Rental::create([
                'number_id' => $number, 'organization_id' => $offer->organization_id, 'vehicle_id' => $offer->vehicle_id,
                'assignment_id' => $assignment?->id, 'customer_id' => $customer->id,
                'pickup_location_id' => $offer->location_id, 'return_location_id' => $offer->location_id,
                'planned_pickup_at' => $start, 'planned_return_at' => $end, 'status' => 'reserved',
                'amount' => number_format($car['total_cents'] / 100, 2, '.', ''),
                'final_amount_override' => number_format($car['total_cents'] / 100, 2, '.', ''),
                'notes' => 'Prenotazione dal sito. Pagamento al ritiro. Completare i dati del conducente e il contratto prima della consegna.',
            ]));
            // Reuse ERA's existing freeze-once pricing snapshot for the future contract too.
            RentalContractSnapshot::create([
                'rental_id' => $rental->id, 'created_by_user_id' => null,
                'pricing_snapshot' => [
                    'pricelist_id' => $offer->pricelist_id, 'currency' => 'EUR', 'days' => $car['days'],
                    'tariff_total_cents' => $car['total_cents'], 'tariff_override_cents' => $car['total_cents'],
                    'km_daily_limit' => $car['km_per_day'], 'extra_km_cents' => $car['extra_km_cents'],
                    'deposit_cents' => $car['deposit_cents'],
                    'second_driver_daily_cents' => (int) ($offer->pricelist->second_driver_daily_cents ?? 0),
                ],
            ]);
            return PublicBooking::create($contact + [
                'reference' => 'AMD-'.Str::upper(Str::random(12)), 'request_hash' => $hash,
                'rental_id' => $rental->id, 'organization_id' => $offer->organization_id, 'public_rental_offer_id' => $offer->id,
                'pickup_at' => $start, 'return_at' => $end, 'total_cents' => $car['total_cents'],
                'deposit_cents' => $car['deposit_cents'], 'payment_method' => 'pay_at_pickup',
                'quote_snapshot' => $car + ['pricelist_id' => $offer->pricelist_id], 'accepted_at' => now(),
            ]);
        });
    }

    public function assertQuote(array $intent, ?array $car): void
    {
        if (!$car) throw ValidationException::withMessages(['booking' => 'L’auto non è più disponibile per questo periodo. Scegli un’altra auto o modifica le date.']);
        if ($intent['expires_at'] < now()->timestamp || !hash_equals($intent['fingerprint'], self::fingerprint($car))) {
            throw ValidationException::withMessages(['booking' => 'Il riepilogo è scaduto o le condizioni sono cambiate. Controlla il riepilogo aggiornato e conferma nuovamente.']);
        }
    }
}
