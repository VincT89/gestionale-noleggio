<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

class PublicBooking extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['request_hash'];
    protected $casts = [
        'quote_snapshot' => 'array', 'pickup_at' => 'datetime', 'return_at' => 'datetime',
        'accepted_at' => 'datetime', 'confirmation_email_sent_at' => 'datetime',
        'total_cents' => 'integer', 'deposit_cents' => 'integer',
    ];

    public function rental() { return $this->belongsTo(Rental::class)->withTrashed(); }
    public function organization() { return $this->belongsTo(Organization::class)->withTrashed(); }
    public function offer() { return $this->belongsTo(PublicRentalOffer::class, 'public_rental_offer_id'); }

    public function confirmationUrl(): string
    {
        return URL::signedRoute('public-bookings.confirmation', ['reference' => $this->reference]);
    }

    public function pdfUrl(bool $download = false): string
    {
        return URL::signedRoute('public-bookings.pdf', ['reference' => $this->reference] + ($download ? ['download' => 1] : []));
    }

    public function shareableConfirmationUrl(): ?string
    {
        $url = $this->confirmationUrl();
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || $host === '' || $host === 'localhost'
            || !str_contains($host, '.') || preg_match('/\.(localhost|local|test|invalid|example)$/', $host)) {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)
            && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }
        return $url;
    }

    public function shareText(): string
    {
        $car = $this->quote_snapshot;
        $confirmationUrl = $this->shareableConfirmationUrl();
        return 'Prenotazione '.$this->reference.' - '.$this->status_label."\n"
            .'Noleggiatore: '.$car['organization']."\n".'Auto: '.$car['title']."\n"
            .'Ritiro: '.$this->pickup_at->format('d/m/Y H:i')."\n"
            .'Riconsegna: '.$this->return_at->format('d/m/Y H:i')."\n"
            .'Sede: '.$car['location'].' - '.$car['city']."\n"
            .'Totale concordato: '.number_format($this->total_cents / 100, 2, ',', '.')." EUR\n"
            .'Cauzione separata: '.number_format($this->deposit_cents / 100, 2, ',', '.')." EUR\n"
            .($this->status_label === 'Confermata' ? "Pagamento al ritiro.\n" : '')
            .($confirmationUrl ? 'Riepilogo e conferma stampabile: '.$confirmationUrl : 'Conserva il riferimento della prenotazione.');
    }

    public function emailComposeUrl(): string
    {
        return 'mailto:'.rawurlencode($this->email).'?subject='.rawurlencode('Prenotazione '.$this->reference)
            .'&body='.rawurlencode($this->shareText());
    }

    public function whatsappComposeUrl(): ?string
    {
        // Do not guess a country code for national numbers.
        $digits = preg_replace('/\D/', '', $this->phone);
        if (!str_starts_with(trim($this->phone), '+') || !preg_match('/^[1-9][0-9]{7,14}$/', $digits)) return null;
        return 'https://wa.me/'.$digits.'?text='.rawurlencode($this->shareText());
    }

    public function getStatusLabelAttribute(): string
    {
        if (!$this->rental || $this->rental->trashed()) return 'Annullata';
        return match ($this->rental->status) {
            'cancelled', 'no_show' => 'Annullata',
            'in_use', 'checked_out' => 'Noleggio in corso',
            'checked_in', 'closed' => 'Noleggio terminato',
            default => 'Confermata',
        };
    }
}
