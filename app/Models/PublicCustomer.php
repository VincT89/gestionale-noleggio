<?php

namespace App\Models;

use App\Notifications\PublicCustomerAccess;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class PublicCustomer extends Authenticatable implements MustVerifyEmail
{
    use Notifiable;

    protected $fillable = ['first_name', 'last_name', 'email', 'phone', 'password'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = ['email_verified_at' => 'datetime', 'password' => 'hashed'];

    public function bookings() { return $this->hasMany(PublicBooking::class); }
    public function enquiries() { return $this->hasMany(AmdRentEnquiry::class); }
    public function getNameAttribute(): string { return trim($this->first_name.' '.$this->last_name); }
    public function sendEmailVerificationNotification() { $this->notify(new PublicCustomerAccess('verify')); }
    public function sendPasswordResetNotification($token) { $this->notify(new PublicCustomerAccess('reset', $token)); }
}
