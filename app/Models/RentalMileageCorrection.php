<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RentalMileageCorrection extends Model
{
    protected $fillable = ['rental_id', 'corrected_by', 'properties'];

    protected $casts = ['properties' => 'array'];
}
