<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalExtension extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'previous_return_at' => 'datetime',
        'new_return_at' => 'datetime',
        'additional_amount' => 'decimal:2',
        'previous_amount' => 'decimal:2',
        'new_amount' => 'decimal:2',
        'previous_override' => 'decimal:2',
        'new_override' => 'decimal:2',
        'previous_pricing' => 'array',
        'new_pricing' => 'array',
    ];

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }
}
