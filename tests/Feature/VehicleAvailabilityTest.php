<?php

namespace Tests\Feature;

use App\Domain\Rentals\VehicleAvailabilityService;
use App\Models\PublicRentalOffer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PublicCarsTestCase;

class VehicleAvailabilityTest extends PublicCarsTestCase
{
    private function available(): array
    {
        return app(VehicleAvailabilityService::class)->availableOfferIds(
            PublicRentalOffer::with(['vehicle.adminOrganization', 'organization'])->get(),
            CarbonImmutable::parse($this->period()['pickup_at']), CarbonImmutable::parse($this->period()['return_at']),
        );
    }

    public static function commitments(): array
    {
        $rental = ['vehicle_id' => 1, 'status' => 'reserved', 'planned_pickup_at' => '2026-09-11 10:00:00', 'planned_return_at' => '2026-09-12 10:00:00'];
        $block = ['vehicle_id' => 1, 'status' => 'active', 'start_at' => '2026-09-11 10:00:00', 'end_at' => '2026-09-12 10:00:00'];
        $state = ['vehicle_id' => 1, 'state' => 'maintenance', 'started_at' => '2026-09-01 10:00:00', 'ended_at' => null];
        return [
            'reservation from another organization' => ['rentals', $rental, false],
            'draft with planned dates' => ['rentals', array_replace($rental, ['status' => 'draft']), false],
            'cancelled reservation' => ['rentals', array_replace($rental, ['status' => 'cancelled']), true],
            'no show' => ['rentals', array_replace($rental, ['status' => 'no_show']), true],
            'deleted reservation' => ['rentals', array_replace($rental, ['deleted_at' => '2026-09-01 10:00:00']), true],
            'return exactly at pickup' => ['rentals', array_replace($rental, ['planned_pickup_at' => '2026-09-09 10:00:00', 'planned_return_at' => '2026-09-10 10:00:00']), true],
            'next pickup exactly at return' => ['rentals', array_replace($rental, ['planned_pickup_at' => '2026-09-13 10:00:00', 'planned_return_at' => '2026-09-14 10:00:00']), true],
            'one minute overlap' => ['rentals', array_replace($rental, ['planned_pickup_at' => '2026-09-09 10:00:00', 'planned_return_at' => '2026-09-10 10:01:00']), false],
            'actual return overrides later plan' => ['rentals', array_replace($rental, ['status' => 'closed', 'planned_pickup_at' => '2026-09-09 10:00:00', 'actual_return_at' => '2026-09-10 10:00:00']), true],
            'actual return extends plan' => ['rentals', array_replace($rental, ['status' => 'closed', 'planned_pickup_at' => '2026-09-09 10:00:00', 'planned_return_at' => '2026-09-10 10:00:00', 'actual_return_at' => '2026-09-10 11:00:00']), false],
            'currently out but due before requested period' => ['rentals', array_replace($rental, ['status' => 'in_use', 'planned_pickup_at' => '2026-09-01 10:00:00', 'planned_return_at' => '2026-09-09 10:00:00']), true],
            'overdue still out blocks future' => ['rentals', array_replace($rental, ['status' => 'in_use', 'planned_pickup_at' => '2026-09-01 10:00:00', 'planned_return_at' => '2026-09-07 10:00:00']), false],
            'checked out with unknown return' => ['rentals', array_replace($rental, ['status' => 'checked_out', 'planned_pickup_at' => '2026-09-01 10:00:00', 'planned_return_at' => null]), false],
            'returned overdue car no longer blocks' => ['rentals', array_replace($rental, ['status' => 'in_use', 'planned_pickup_at' => '2026-09-01 10:00:00', 'planned_return_at' => '2026-09-07 10:00:00', 'actual_return_at' => '2026-09-08 08:00:00']), true],
            'active block' => ['vehicle_blocks', $block, false],
            'scheduled block' => ['vehicle_blocks', array_replace($block, ['status' => 'scheduled']), false],
            'open ended block' => ['vehicle_blocks', array_replace($block, ['end_at' => null]), false],
            'cancelled block' => ['vehicle_blocks', array_replace($block, ['status' => 'cancelled']), true],
            'ended block' => ['vehicle_blocks', array_replace($block, ['status' => 'ended']), true],
            'block ends at pickup' => ['vehicle_blocks', array_replace($block, ['start_at' => '2026-09-09 10:00:00', 'end_at' => '2026-09-10 10:00:00']), true],
            'ongoing maintenance' => ['vehicle_states', $state, false],
            'blocked technical state' => ['vehicle_states', array_replace($state, ['state' => 'blocked']), false],
            'out of service' => ['vehicle_states', array_replace($state, ['state' => 'out_of_service']), false],
            'finished maintenance' => ['vehicle_states', array_replace($state, ['ended_at' => '2026-09-10 10:00:00']), true],
            'derivative rented state does not block every future day' => ['vehicle_states', array_replace($state, ['state' => 'rented']), true],
        ];
    }

    #[DataProvider('commitments')]
    public function test_full_period_conflicts(string $table, array $record, bool $available): void
    {
        $offer = $this->offer();
        DB::table($table)->insert($record);
        $this->assertSame($available ? [$offer->id] : [], $this->available());
    }

    public static function assignments(): array
    {
        return [
            'owner without assignment' => [1, [], true],
            'owner during another renter assignment' => [1, [[2, '2026-09-01', null]], false],
            'renter without assignment' => [2, [], false],
            'renter covers full period' => [2, [[2, '2026-09-01', null]], true],
            'assignment starts after pickup' => [2, [[2, '2026-09-11', null]], false],
            'assignment ends before return' => [2, [[2, '2026-09-01', '2026-09-12']], false],
            'adjacent assignments cover period' => [2, [[2, '2026-09-01', '2026-09-12'], [2, '2026-09-12', '2026-09-14']], true],
            'overlapping assignments cover period' => [2, [[2, '2026-09-01', '2026-09-12'], [2, '2026-09-11', '2026-09-14']], true],
            'gap between assignments' => [2, [[2, '2026-09-01', '2026-09-11'], [2, '2026-09-12', '2026-09-14']], false],
            'another renter overlaps valid assignment' => [2, [[2, '2026-09-01', null], [3, '2026-09-12', '2026-09-13']], false],
            'future scheduled assignment covers requested dates' => [2, [[2, '2026-09-10 10:00:00', '2026-09-13 10:00:00']], true],
            'owner available after assignment return' => [1, [[2, '2026-09-01', '2026-09-10 10:00:00']], true],
        ];
    }

    #[DataProvider('assignments')]
    public function test_assignment_must_cover_the_whole_requested_period(int $organization, array $periods, bool $available): void
    {
        $offer = $this->offer(organization: $organization);
        foreach ($periods as [$renter, $start, $end]) {
            DB::table('vehicle_assignments')->insert([
                'vehicle_id' => 1, 'renter_org_id' => $renter, 'status' => 'scheduled',
                'start_at' => CarbonImmutable::parse($start), 'end_at' => $end ? CarbonImmutable::parse($end) : null,
            ]);
        }
        $this->assertSame($available ? [$offer->id] : [], $this->available());
    }

    public function test_inactive_and_archived_entities_cannot_be_offered(): void
    {
        $this->offer();
        DB::table('vehicles')->where('id', 1)->update(['is_active' => false]);
        $this->assertSame([], $this->available());
        DB::table('vehicles')->where('id', 1)->update(['is_active' => true]);
        DB::table('organizations')->where('id', 1)->update(['is_active' => false]);
        $this->assertSame([], $this->available());
        DB::table('organizations')->where('id', 1)->update(['is_active' => true, 'deleted_at' => now()]);
        $this->assertSame([], $this->available());
    }
}
