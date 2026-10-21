<?php

namespace App\Services\AmdRent;

class CustomerDeposit
{
    public const BPS = 1500;

    public static function currentBps(): int
    {
        return config('amd_rent.payment_mode') === 'stripe' ? self::BPS : 0;
    }

    public static function label(?int $bps = null): string
    {
        return Commission::label($bps ?? self::currentBps());
    }
}
