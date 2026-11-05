<?php

namespace Tests\Feature;

use App\Services\AmdRent\Commission;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};
use Tests\Support\PublicBookingTestCase;

class AmdRentCommissionDefaultsTest extends PublicBookingTestCase
{
    public function test_fresh_settings_use_fifteen_percent_in_calculations_and_in_the_input(): void
    {
        $this->assertSame(1500, Commission::currentBps());
        $this->actingAs($this->publisher());
        $page = $this->get(route('amd-rent.settings'))->assertOk()->assertViewHas('commissionBps', 1500);
        $document = new \DOMDocument();
        $document->loadHTML($page->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $this->assertSame('15', $document->getElementById('commission-percent')->getAttribute('value'));

        DB::table('amd_rent_settings')->insert(['id' => 1]);
        $this->assertSame(1500, (int) DB::table('amd_rent_settings')->value('commission_bps'));
        $this->assertSame(1500, Commission::currentBps());
    }

    public function test_default_migration_preserves_saved_percentages_including_zero(): void
    {
        $migration = require database_path('migrations/2026_11_05_120000_set_default_amd_rent_commission_to_fifteen_percent.php');
        $migration->down();
        DB::table('amd_rent_settings')->insert([
            ['id' => 1, 'commission_bps' => 2000],
            ['id' => 2, 'commission_bps' => 0],
            ['id' => 3, 'commission_bps' => 2550],
        ]);
        $migration->up();
        $this->assertSame([2000, 0, 2550], DB::table('amd_rent_settings')->orderBy('id')->pluck('commission_bps')->map(fn ($rate) => (int) $rate)->all());
        DB::table('amd_rent_settings')->insert(['id' => 4]);
        $this->assertSame(1500, (int) DB::table('amd_rent_settings')->where('id', 4)->value('commission_bps'));
        $this->assertSame(2000, Commission::currentBps());
        $this->actingAs($this->publisher())->get(route('amd-rent.settings'))->assertOk()->assertViewHas('commissionBps', 2000);
    }

    public function test_fallback_is_fifteen_percent_before_the_settings_column_exists(): void
    {
        Schema::table('amd_rent_settings', fn (Blueprint $table) => $table->dropColumn('commission_bps'));
        $this->assertSame(1500, Commission::currentBps());
    }
}
