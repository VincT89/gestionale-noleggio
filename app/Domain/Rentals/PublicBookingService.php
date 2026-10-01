<?php

namespace App\Domain\Rentals;

use App\Models\{Customer, PublicBooking, PublicRentalOffer, Rental, RentalContractSnapshot, VehicleAssignment, VehiclePricelist};
use App\Services\Rentals\RentalNumberAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicBookingService
{
    public function __construct(private PublicVehicleSearch $search, private RentalNumberAllocator $numbers, private ReservationTransaction $transaction) {}

    public static function fingerprint(array $car): string
    {
        $fields = ['id', 'vehicle_id', 'supplier_id', 'title', 'organization', 'location', 'city', 'address', 'description', 'total_cents',
            'days', 'deposit_cents', 'km_per_day', 'extra_km_cents', 'prices_include_vat', 'place_id', 'pickup_location_id',
            'custom_delivery_enabled', 'delivery_request_id', 'delivery_fee_cents', 'delivery_commission_bps', 'delivery_address', 'delivery_revision'];
        return hash('sha256', json_encode(array_intersect_key($car, array_flip($fields)), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function reserve(array $intent, array $contact): PublicBooking
    {
        $hash = hash('sha256', $intent['nonce']);
        if ($existing = PublicBooking::where('request_hash', $hash)->first()) return $existing;
        $stripe = config('amd_rent.payment_mode') === 'stripe';
        if ($stripe) app(\App\Services\AmdRent\StripeCheckout::class)->requireReady();
        $candidate = VehiclePricelist::forPublicRental()->findOrFail($intent['pricelist']);

        return $this->transaction->run($candidate->renter_org_id, [$candidate->vehicle_id], function () use ($candidate, $intent, $contact, $hash, $stripe) {
            if ($existing = PublicBooking::where('request_hash', $hash)->lockForUpdate()->first()) return $existing;
            $pricelist = VehiclePricelist::forPublicRental()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if ($pricelist->vehicle_id !== $candidate->vehicle_id || $pricelist->renter_org_id !== $candidate->renter_org_id) {
                throw ValidationException::withMessages(['booking' => 'L’offerta è cambiata. Riapri il riepilogo prima di confermare.']);
            }
            $car = $this->search->search(VehiclePricelist::forPublicRental()->whereKey($pricelist->id), $intent['period'])->first();
            $deliveryRequest = null;
            if (!empty($intent['delivery_request_id'])) {
                [$deliveryRequest, $attempts] = app(\App\Services\AmdRent\DeliveryBookingRecovery::class)->lockRequest($intent['delivery_request_id']);
                if ($deliveryRequest->public_booking_id) return $deliveryRequest->booking;
                if ($car) $car = app(\App\Services\AmdRent\DeliveryQuotes::class)->apply($car, $deliveryRequest, $attempts);
            }
            $this->assertQuote($intent, $car);
            $onlineDue = $stripe ? \App\Services\AmdRent\DeliveryQuotes::onlineDue($car) : 0;
            if ($stripe && $onlineDue < 50) throw ValidationException::withMessages(['booking' => 'L’importo online è inferiore al minimo supportato. Contatta AMD Rent.']);

            // An unauthenticated visitor cannot claim or overwrite an existing customer by email.
            $customer = Customer::create($contact + [
                'organization_id' => $pricelist->renter_org_id, 'name' => trim($contact['first_name'].' '.$contact['last_name']),
            ]);
            $start = CarbonImmutable::parse($intent['period']['pickup_at'], config('app.timezone'));
            $end = CarbonImmutable::parse($intent['period']['return_at'], config('app.timezone'));
            $assignment = VehicleAssignment::where('vehicle_id', $pricelist->vehicle_id)->where('renter_org_id', $pricelist->renter_org_id)
                ->whereIn('status', ['active', 'scheduled'])->where('start_at', '<=', $start)
                ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>', $start))->latest('start_at')->first();
            $rental = $this->numbers->allocateAndCreate($pricelist->renter_org_id, null, fn (int $number) => Rental::create([
                'number_id' => $number, 'organization_id' => $pricelist->renter_org_id, 'vehicle_id' => $pricelist->vehicle_id,
                'assignment_id' => $assignment?->id, 'customer_id' => $customer->id,
                'pickup_location_id' => $car['pickup_location_id'], 'return_location_id' => $car['pickup_location_id'],
                'planned_pickup_at' => $start, 'planned_return_at' => $end, 'status' => 'reserved',
                'amount' => number_format($car['total_cents'] / 100, 2, '.', ''),
                'final_amount_override' => number_format($car['total_cents'] / 100, 2, '.', ''),
                'booking_channel' => $stripe ? 'amd_rent' : null,
                'notes' => ($stripe ? 'Prenotazione AMD Rent: quota online a AMD Rent, saldo al ritiro. ' : 'Prenotazione dal sito. Pagamento al ritiro. ')
                    .(!empty($car['delivery_address']) ? 'Consegna concordata: '.$car['delivery_address'].'. ' : '')
                    .'Completare i dati del conducente e il contratto prima della consegna.',
            ]));
            // Reuse ERA's existing freeze-once pricing snapshot for the future contract too.
            RentalContractSnapshot::create([
                'rental_id' => $rental->id, 'created_by_user_id' => null,
                'pricing_snapshot' => [
                    'pricelist_id' => $pricelist->id, 'currency' => 'EUR', 'days' => $car['days'],
                    'tariff_total_cents' => $car['total_cents'], 'tariff_override_cents' => $car['total_cents'],
                    'km_daily_limit' => $car['km_per_day'], 'extra_km_cents' => $car['extra_km_cents'],
                    'deposit_cents' => $car['deposit_cents'],
                    'second_driver_daily_cents' => (int) ($pricelist->second_driver_daily_cents ?? 0),
                ],
            ]);
            // Compatibility with existing booking foreign keys. Search never reads or creates offers;
            // historical records stay untouched and the accepted pricelist lives in the snapshots.
            $record = PublicRentalOffer::firstOrCreate([
                'vehicle_id' => $pricelist->vehicle_id, 'organization_id' => $pricelist->renter_org_id,
            ], [
                'location_id' => $car['pickup_location_id'], 'pricelist_id' => $pricelist->id,
                'prices_include_vat' => true, 'is_published' => false,
            ]);
            $booking = PublicBooking::create($contact + [
                'reference' => 'AMD-'.Str::upper(Str::random(12)), 'request_hash' => $hash,
                'rental_id' => $rental->id, 'organization_id' => $pricelist->renter_org_id, 'public_rental_offer_id' => $record->id,
                'pickup_at' => $start, 'return_at' => $end, 'total_cents' => $car['total_cents'],
                'deposit_cents' => $car['deposit_cents'], 'payment_method' => $stripe ? 'stripe' : 'pay_at_pickup',
                'payment_status' => $stripe ? 'pending' : 'pickup', 'online_due_cents' => $onlineDue,
                'payment_expires_at' => $stripe ? now()->addMinutes(35) : null,
                'delivery_fee_cents' => $car['delivery_fee_cents'] ?? 0, 'delivery_commission_bps' => $car['delivery_commission_bps'] ?? null,
                'quote_snapshot' => $car + ['pricelist_id' => $pricelist->id], 'accepted_at' => now(),
            ]);
            if ($deliveryRequest) $deliveryRequest->update(['public_booking_id' => $booking->id, 'status' => 'converted', 'revision' => $deliveryRequest->revision + 1]);
            return $booking;
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
