<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

class AmdRentEnquiry extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['request_hash', 'platform_commission_cents'];
    protected $casts = ['booking_context' => 'array', 'quote_expires_at' => 'datetime', 'signed_at' => 'date', 'revision' => 'integer'];
    public const STATUSES = ['new' => 'Da gestire', 'working' => 'In lavorazione', 'quoted' => 'Preventivo pronto', 'accepted' => 'Accettato', 'signed' => 'Contratto concluso in presenza', 'lost' => 'Archiviata', 'converted' => 'Prenotazione creata'];
    public function organization() { return $this->belongsTo(Organization::class); }
    public function quotes() { return $this->hasMany(AmdRentQuote::class, 'enquiry_id'); }
    public function documents() { return $this->hasMany(AmdRentDocument::class, 'enquiry_id'); }
    public function events() { return $this->hasMany(AmdRentEvent::class, 'enquiry_id'); }
    public function booking() { return $this->belongsTo(PublicBooking::class, 'public_booking_id'); }
    public function publicUrl(): string { return URL::signedRoute('public-enquiries.show', ['reference' => $this->reference]); }
    public function getStatusLabelAttribute(): string { return self::STATUSES[$this->status] ?? $this->status; }
}
