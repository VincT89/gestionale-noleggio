<?php

namespace App\Services\AmdRent;

use Illuminate\Support\Facades\{DB, Schema};

class Commission
{
    public static function earnedCents(\App\Models\PublicBooking $booking): int
    {
        $netPaid = max(0, $booking->online_paid_cents - $booking->refunded_cents);
        if (!isset($booking->quote_snapshot['customer_deposit_bps'])) return $netPaid;
        if ($booking->online_due_cents <= 0 || $netPaid === 0) return 0;
        /** A partial deposit refund increases the customer's pickup balance; it does not change the agreed rental total or commission rate. */
        return intdiv($booking->total_cents * (int) $booking->quote_snapshot['commission_bps'] + 5000, 10000);
    }

    public static function collectedCents(\App\Models\PublicBooking $booking): int
    {
        return min(self::earnedCents($booking), max(0, $booking->online_paid_cents - $booking->refunded_cents));
    }

    public static function currentBps(): int
    {
        // Default also covers a fresh installation before its first settings save.
        if (!Schema::hasColumn('amd_rent_settings', 'commission_bps')) return 2000;
        return (int) (DB::table('amd_rent_settings')->where('id', 1)->value('commission_bps') ?? 2000);
    }

    public static function label(?int $bps = null): string
    {
        return rtrim(rtrim(number_format(($bps ?? self::currentBps()) / 100, 2, ',', ''), '0'), ',').'%';
    }
}
