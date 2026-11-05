<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicRentalOffer extends Model
{
    protected $fillable = [
        'vehicle_id', 'organization_id', 'location_id', 'pricelist_id',
        'description', 'prices_include_vat', 'is_published', 'created_by',
    ];

    protected $casts = ['prices_include_vat' => 'boolean', 'is_published' => 'boolean'];

    public function vehicle(): BelongsTo { return $this->belongsTo(Vehicle::class); }
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
    public function pricelist(): BelongsTo { return $this->belongsTo(VehiclePricelist::class, 'pricelist_id'); }

    public function scopeEligible(Builder $query): Builder
    {
        return $query
            ->whereHas('vehicle', fn (Builder $q) => $q->where('is_active', true)
                ->whereHas('adminOrganization', fn (Builder $owner) => $owner->where('is_active', true)))
            ->whereHas('organization', fn (Builder $q) => $q->where('is_active', true))
            ->whereHas('location', fn (Builder $q) => $q
                ->whereColumn('locations.organization_id', 'public_rental_offers.organization_id'))
            ->whereHas('pricelist', fn (Builder $q) => $q
                ->where('status', 'active')->where('active_flag', true)->where('currency', 'EUR')
                ->where('base_daily_cents', '>=', 0)
                ->whereColumn('vehicle_pricelists.vehicle_id', 'public_rental_offers.vehicle_id')
                ->whereColumn('vehicle_pricelists.renter_org_id', 'public_rental_offers.organization_id'));
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->eligible()->where('is_published', true)->where('prices_include_vat', true);
    }
}
