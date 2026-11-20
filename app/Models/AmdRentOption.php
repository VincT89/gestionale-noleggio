<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmdRentOption extends Model
{
    public const MULTIPLIER_SCALE = 10000;

    public const MAX_MULTIPLIER = 100;

    public const KINDS = [
        'standard' => 'Protezione standard',
        'plus' => 'AMD Plus',
        'service' => 'Servizio extra',
    ];

    public const COVERAGES = [
        'rca' => 'Responsabilità civile (RCA)',
        'kasko' => 'Danni al veicolo (Kasko)',
        'furto_incendio' => 'Furto e incendio',
        'cristalli' => 'Cristalli',
        'assistenza' => 'Assistenza stradale',
    ];

    public const VEHICLE_FRANCHISES = [
        'rca' => 'insurance_rca_cents',
        'kasko' => 'insurance_kasko_cents',
        'furto_incendio' => 'insurance_furto_cents',
        'cristalli' => 'insurance_cristalli_cents',
    ];

    protected $fillable = [
        'organization_id', 'vehicle_id', 'vehicle_product_id', 'kind', 'name', 'description', 'exclusions',
        'coverage', 'franchise_multipliers', 'protection_type', 'provider', 'terms_url',
        'price_cents', 'price_multiplier_bps', 'price_unit', 'depositable',
        'commissionable', 'covers_second_driver', 'published', 'revision',
    ];

    protected $casts = [
        'coverage' => 'array',
        'franchise_multipliers' => 'array',
        'price_cents' => 'integer',
        'price_multiplier_bps' => 'integer',
        'depositable' => 'boolean',
        'commissionable' => 'boolean',
        'covers_second_driver' => 'boolean',
        'published' => 'boolean',
        'revision' => 'integer',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(VehicleProduct::class, 'vehicle_product_id');
    }

    public function scopeKey(): string
    {
        if ($this->vehicle_id) {
            return 'vehicle:'.$this->vehicle_id;
        }

        return $this->vehicle_product_id ? 'product:'.$this->vehicle_product_id : 'all';
    }
}
