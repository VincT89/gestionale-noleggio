<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_pickup_places', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('city', 128)->nullable();
            $table->string('kind', 20)->default('location');
            $table->string('address_line', 191)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->char('identity_key', 64)->unique();
            $table->timestamps();
        });
        Schema::create('public_delivery_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('public_pickup_place_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'public_pickup_place_id'], 'delivery_org_place_unique');
        });

        // Preserve only the pickup points already explicitly configured in offers, including drafts.
        // Different names/addresses are not assumed to denote the same airport or area.
        DB::table('locations')->whereIn('id', DB::table('public_rental_offers')->select('location_id')
            ->whereColumn('public_rental_offers.organization_id', 'locations.organization_id'))
            ->orderBy('id')->chunkById(200, function ($locations) {
                foreach ($locations as $location) {
                    $data = array_intersect_key((array) $location, array_flip(['name', 'city', 'address_line', 'country_code']));
                    $parts = array_map(fn ($field) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($data[$field] ?? ''))),
                        ['name', 'city', 'address_line', 'country_code']);
                    $key = hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    $placeId = DB::table('public_pickup_places')->where('identity_key', $key)->value('id');
                    if (!$placeId) $placeId = DB::table('public_pickup_places')->insertGetId($data + [
                        'kind' => 'location', 'identity_key' => $key, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('public_delivery_locations')->updateOrInsert([
                        'organization_id' => $location->organization_id, 'public_pickup_place_id' => $placeId,
                    ], ['location_id' => $location->id, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_delivery_locations');
        Schema::dropIfExists('public_pickup_places');
    }
};
