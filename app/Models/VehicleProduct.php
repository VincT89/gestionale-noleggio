<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class VehicleProduct extends Model
{
    protected $fillable = ['name', 'description'];

    public function setNameAttribute(string $value): void
    {
        $this->attributes['name'] = Str::squish($value);
        $this->attributes['name_key'] = mb_strtolower($this->attributes['name']);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }
}
