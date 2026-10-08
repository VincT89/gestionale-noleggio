<?php

namespace App\Services\AmdRent;

use Illuminate\Support\Facades\{DB, Schema};

class Commission
{
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
