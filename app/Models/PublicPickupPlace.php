<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PublicPickupPlace extends Model
{
    public const KINDS = ['airport' => 'Aeroporto', 'station' => 'Stazione', 'city' => 'Città', 'area' => 'Zona', 'location' => 'Punto di ritiro'];

    protected $fillable = ['name', 'city', 'kind', 'address_line', 'country_code', 'identity_key'];

    public function deliveries(): HasMany { return $this->hasMany(PublicDeliveryLocation::class); }

    public static function identity(array $data): string
    {
        $parts = array_map(fn ($field) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($data[$field] ?? ''))),
            ['name', 'city', 'address_line', 'country_code']);
        return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function getLabelAttribute(): string
    {
        return $this->name.($this->city ? ' — '.$this->city : '').' ('.self::KINDS[$this->kind].')';
    }
}
