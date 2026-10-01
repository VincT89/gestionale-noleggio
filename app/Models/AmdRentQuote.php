<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AmdRentQuote extends Model {
    protected $guarded = ['id'];
    protected $casts = ['valid_until' => 'date'];
}
