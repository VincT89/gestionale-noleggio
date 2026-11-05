<?php

namespace App\Services\AmdRent;

use App\Models\{AmdRentEnquiry, PublicBooking, PublicCustomer};
use Illuminate\Support\Facades\DB;

class PublicCustomerRecords
{
    /** Claim only public website records after proving ownership of the contact email. */
    public function link(PublicCustomer $customer): void
    {
        if (!$customer->hasVerifiedEmail()) return;
        DB::transaction(function () use ($customer) {
            $verified = PublicCustomer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            if (!$verified->hasVerifiedEmail()) return;
            foreach ([PublicBooking::class, AmdRentEnquiry::class] as $model) {
                $model::whereNull('public_customer_id')
                    ->whereRaw('LOWER(TRIM(email)) = ?', [mb_strtolower(trim($verified->email))])
                    ->update(['public_customer_id' => $verified->id]);
            }
        });
    }
}
