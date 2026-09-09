<?php

namespace Tests\Support;

use App\Models\PublicRentalOffer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

abstract class PublicCarsTestCase extends TestCase
{
    private const CONNECTION = 'public_cars_test';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.connections.'.self::CONNECTION, [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        DB::setDefaultConnection(self::CONNECTION);
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-09-08 09:00:00', 'Europe/Rome'));

        // Isolated schema: never run the application's historical migrations on an imported database.
        Schema::create('organizations', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('type')->default('renter');
            $t->boolean('is_active')->default(true); $t->softDeletes(); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('organization_id'); $t->string('name');
            $t->string('email')->unique(); $t->string('password'); $t->boolean('is_active')->default(true);
            $t->timestamp('email_verified_at')->nullable(); $t->rememberToken(); $t->softDeletes(); $t->timestamps();
        });
        Schema::create('locations', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('organization_id'); $t->string('name');
            $t->string('city'); $t->string('address_line')->nullable(); $t->timestamps();
        });
        Schema::create('vehicles', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('admin_organization_id');
            $t->unsignedBigInteger('default_pickup_location_id')->nullable();
            $t->string('plate'); $t->string('vin')->nullable(); $t->string('make'); $t->string('model');
            $t->string('segment')->nullable(); $t->string('fuel_type')->nullable(); $t->string('transmission')->nullable();
            $t->integer('seats')->nullable(); $t->integer('year')->nullable();
            $t->integer('lt_rental_monthly_cents')->default(80000); $t->boolean('is_active')->default(true);
            $t->softDeletes(); $t->timestamps();
        });
        Schema::create('vehicle_pricelists', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('vehicle_id'); $t->unsignedBigInteger('renter_org_id');
            $t->string('name')->default('Listino di prova'); $t->string('currency')->default('EUR');
            $t->string('status')->default('active'); $t->boolean('active_flag')->nullable()->default(true);
            $t->boolean('is_active')->default(true); $t->integer('version')->default(1);
            $t->integer('base_daily_cents')->default(10000); $t->integer('weekend_pct')->default(0);
            $t->integer('km_included_per_day')->nullable()->default(100);
            $t->integer('extra_km_cents')->default(30); $t->integer('deposit_cents')->default(50000);
            $t->string('rounding')->default('none'); $t->timestamp('published_at')->nullable(); $t->timestamps();
        });
        Schema::create('vehicle_pricelist_seasons', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('vehicle_pricelist_id'); $t->string('name');
            $t->string('start_mmdd'); $t->string('end_mmdd'); $t->integer('season_pct');
            $t->integer('weekend_pct_override')->nullable(); $t->integer('priority')->default(0);
            $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('vehicle_pricelist_tiers', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('vehicle_pricelist_id'); $t->string('name');
            $t->integer('min_days'); $t->integer('max_days')->nullable(); $t->integer('override_daily_cents')->nullable();
            $t->integer('discount_pct')->nullable(); $t->integer('priority')->default(0);
            $t->boolean('is_active')->default(true); $t->timestamps();
        });
        Schema::create('rentals', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('organization_id')->default(3); $t->unsignedBigInteger('vehicle_id');
            $t->string('status');
            foreach (['planned_pickup_at', 'planned_return_at', 'actual_pickup_at', 'actual_return_at'] as $field) {
                $t->dateTime($field)->nullable();
            }
            $t->softDeletes(); $t->timestamps();
        });
        foreach (['vehicle_assignments', 'vehicle_blocks'] as $name) {
            Schema::create($name, function (Blueprint $t) use ($name) {
                $t->id(); $t->unsignedBigInteger('vehicle_id'); $t->string('status')->default('active');
                $t->dateTime('start_at'); $t->dateTime('end_at')->nullable();
                if ($name === 'vehicle_assignments') $t->unsignedBigInteger('renter_org_id');
                $t->timestamps();
            });
        }
        Schema::create('vehicle_states', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('vehicle_id'); $t->string('state');
            $t->dateTime('started_at'); $t->dateTime('ended_at')->nullable(); $t->timestamps();
        });
        Schema::create('media', function (Blueprint $t) {
            $t->id(); $t->morphs('model'); $t->string('collection_name'); $t->string('name');
            $t->string('file_name'); $t->string('mime_type'); $t->string('disk');
            $t->string('conversions_disk')->default('public'); $t->unsignedBigInteger('size')->default(0);
            foreach (['manipulations','custom_properties','generated_conversions','responsive_images'] as $field) $t->text($field)->default('{}');
            $t->integer('order_column')->nullable(); $t->timestamps();
        });
        foreach (['roles', 'permissions'] as $name) {
            Schema::create($name, function (Blueprint $t) { $t->id(); $t->string('name'); $t->string('guard_name')->default('web'); $t->timestamps(); });
        }
        foreach (['model_has_roles' => 'role_id', 'model_has_permissions' => 'permission_id'] as $name => $key) {
            Schema::create($name, function (Blueprint $t) use ($key) { $t->unsignedBigInteger($key); $t->string('model_type'); $t->unsignedBigInteger('model_id'); });
        }
        Schema::create('role_has_permissions', function (Blueprint $t) { $t->unsignedBigInteger('role_id'); $t->unsignedBigInteger('permission_id'); });
        (require database_path('migrations/2026_09_08_120000_create_public_rental_offers_table.php'))->up();

        foreach ([1 => 'Proprietario di prova', 2 => 'Noleggiatore di prova', 3 => 'Altro noleggiatore di prova'] as $id => $name) {
            DB::table('organizations')->insert(['id' => $id, 'name' => $name, 'type' => $id === 1 ? 'admin' : 'renter']);
            DB::table('locations')->insert(['id' => $id, 'organization_id' => $id, 'name' => 'Sede di prova '.$id, 'city' => $id === 3 ? 'Roma' : 'Bari', 'address_line' => 'Indirizzo di prova']);
        }
        DB::table('roles')->insert([['id' => 1, 'name' => 'admin'], ['id' => 2, 'name' => 'renter'], ['id' => 3, 'name' => 'viewer']]);
        DB::table('permissions')->insert(['id' => 1, 'name' => 'vehicle_pricing.update']);
        DB::table('role_has_permissions')->insert(['role_id' => 2, 'permission_id' => 1]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        DB::purge(self::CONNECTION);
        parent::tearDown();
    }

    protected function offer(int $id = 1, int $organization = 1, array $price = [], array $vehicle = [], array $offer = []): PublicRentalOffer
    {
        DB::table('vehicles')->insert(array_replace([
            'id' => $id, 'admin_organization_id' => 1, 'default_pickup_location_id' => $organization,
            'plate' => 'TESTPLATE'.$id, 'vin' => 'PRIVATE-VIN-'.$id, 'make' => 'MarcaTest', 'model' => 'ModelloTest '.$id,
            'seats' => 5, 'fuel_type' => 'petrol', 'transmission' => 'manual', 'segment' => 'B', 'year' => 2024,
        ], $vehicle));
        DB::table('vehicle_pricelists')->insert(array_replace(['id' => $id, 'vehicle_id' => $id, 'renter_org_id' => $organization], $price));
        return PublicRentalOffer::create(array_replace([
            'vehicle_id' => $id, 'organization_id' => $organization, 'location_id' => $organization,
            'pricelist_id' => $id, 'prices_include_vat' => true, 'is_published' => true,
        ], $offer));
    }

    protected function publisher(int $organization = 1, string $role = 'admin'): User
    {
        $id = DB::table('users')->insertGetId([
            'organization_id' => $organization, 'name' => 'Utente di prova', 'email' => 'fixture'.$organization.'@example.test',
            'password' => bcrypt('test-password'), 'email_verified_at' => now(),
        ]);
        DB::table('model_has_roles')->insert(['role_id' => ['admin' => 1, 'renter' => 2, 'viewer' => 3][$role], 'model_type' => User::class, 'model_id' => $id]);
        return User::findOrFail($id);
    }

    protected function period(array $overrides = []): array
    {
        return array_replace(['pickup_at' => '2026-09-10T10:00', 'return_at' => '2026-09-13T10:00'], $overrides);
    }
}
