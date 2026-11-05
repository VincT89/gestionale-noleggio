<?php

namespace Tests\Feature;

use App\Models\{Rental, RentalChecklist, RentalDamage};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Gate, Schema};
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\PublicBookingTestCase;

class RentalAccessBoundaryTest extends PublicBookingTestCase
{
    private const ACTIONS = [
        'checkout' => 'rentals.checkout', 'inuse' => 'rentals.inuse',
        'checkin' => 'rentals.checkin', 'close' => 'rentals.close',
        'cancel' => 'rentals.cancel', 'noshow' => 'rentals.noshow',
        'deleteMedia' => 'media.delete',
    ];

    private function grantActions(array $permissions): void
    {
        foreach (array_unique($permissions) as $permission) {
            $id = DB::table('permissions')->insertGetId(['name' => $permission]);
            DB::table('role_has_permissions')->insert(['role_id' => 2, 'permission_id' => $id]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_operational_actions_require_access_to_the_rental_organization(): void
    {
        $this->grantActions(array_values(self::ACTIONS));
        $user = $this->publisher(2, 'renter');
        $own = new Rental(['organization_id' => 2]);
        $foreign = new Rental(['organization_id' => 3]);

        foreach (array_keys(self::ACTIONS) as $action) {
            $this->assertTrue(Gate::forUser($user)->allows($action, $own), $action.' on own rental');
            $this->assertFalse(Gate::forUser($user)->allows($action, $foreign), $action.' on another organization');
        }
    }

    public function test_operational_permissions_are_still_required_and_admin_access_is_preserved(): void
    {
        $user = $this->publisher(2, 'renter');
        $admin = $this->publisher(1, 'admin');
        $rental = new Rental(['organization_id' => 2]);

        foreach (array_keys(self::ACTIONS) as $action) {
            $this->assertFalse(Gate::forUser($user)->allows($action, $rental), $action.' without permission');
            $this->assertTrue(Gate::forUser($admin)->allows($action, $rental), $action.' as admin');
        }
    }

    public function test_distance_overage_cannot_read_another_organizations_rental(): void
    {
        $this->grantActions(['rentals.update']);
        $this->offer();
        $id = DB::table('rentals')->insertGetId(['organization_id' => 3, 'vehicle_id' => 1, 'status' => 'reserved']);
        $this->actingAs($this->publisher(2, 'renter'))
            ->getJson('/rentals/'.$id.'/distance-overage')->assertForbidden();
    }

    public function test_checklist_access_and_attachments_follow_the_parent_rental(): void
    {
        $this->grantActions(['rental_checklists.update', 'media.attach.checklist_photo', 'media.upload', 'media.delete']);
        $user = $this->publisher(2, 'renter');
        $own = (new RentalChecklist())->setRelation('rental', new Rental(['organization_id' => 2]));
        $foreign = (new RentalChecklist())->setRelation('rental', new Rental(['organization_id' => 3]));

        foreach (['view', 'update', 'uploadPhoto', 'uploadSignature', 'deleteMedia', 'generatePdf'] as $action) {
            $this->assertTrue(Gate::forUser($user)->allows($action, $own), $action.' on own checklist');
            $this->assertFalse(Gate::forUser($user)->allows($action, $foreign), $action.' on another checklist');
        }

        $own->locked_at = now();
        $this->assertTrue(Gate::forUser($user)->allows('view', $own));
        foreach (['update', 'uploadPhoto', 'uploadSignature', 'deleteMedia', 'generatePdf'] as $action) {
            $this->assertFalse(Gate::forUser($user)->allows($action, $own), $action.' on locked checklist');
        }
    }

    public function test_damage_actions_follow_the_parent_rental(): void
    {
        Schema::create('rental_checklists', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('rental_id'); $table->string('type');
        });
        $this->grantActions(['rental_damages.update', 'rental_damages.delete', 'media.attach.damage_photo', 'media.delete']);
        $user = $this->publisher(2, 'renter');
        $own = (new RentalDamage())->setRelation('rental', new Rental(['organization_id' => 2]));
        $foreign = (new RentalDamage())->setRelation('rental', new Rental(['organization_id' => 3]));

        foreach (['update', 'delete', 'uploadPhoto', 'deleteMedia'] as $action) {
            $this->assertTrue(Gate::forUser($user)->allows($action, $own), $action.' on own damage');
            $this->assertFalse(Gate::forUser($user)->allows($action, $foreign), $action.' on another damage');
        }
    }
}
