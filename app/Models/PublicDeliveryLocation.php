<?php

namespace App\Models;

use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicDeliveryLocation extends Model
{
    protected $fillable = ['organization_id', 'public_pickup_place_id', 'location_id', 'is_active', 'custom_delivery_enabled', 'delivery_area', 'delivery_origin_location_id', 'delivery_radius_km'];
    protected $casts = ['is_active' => 'boolean', 'custom_delivery_enabled' => 'boolean', 'delivery_radius_km' => 'decimal:2'];

    public function place(): BelongsTo { return $this->belongsTo(PublicPickupPlace::class, 'public_pickup_place_id'); }
    public function location(): BelongsTo { return $this->belongsTo(Location::class); }
    public function origin(): BelongsTo { return $this->belongsTo(Location::class, 'delivery_origin_location_id'); }
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->whereHas('organization', fn ($q) => $q->where('is_active', true))
            ->whereHas('location', fn ($q) => $q->whereColumn('locations.organization_id', 'public_delivery_locations.organization_id'));
    }

    /** Existing offer locations remain explicit pickup points; no surrounding area is inferred. */
    public static function forLocation(Location $location): self
    {
        $data = $location->only(['name', 'city', 'address_line', 'country_code']);
        $place = PublicPickupPlace::firstOrCreate(['identity_key' => PublicPickupPlace::identity($data)], $data + ['kind' => 'location']);
        return static::firstOrCreate(['organization_id' => $location->organization_id, 'public_pickup_place_id' => $place->id],
            ['location_id' => $location->id, 'is_active' => true]);
    }
}
