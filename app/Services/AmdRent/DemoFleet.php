<?php

namespace App\Services\AmdRent;

use App\Models\{Location, Organization, PublicDeliveryLocation, PublicPickupPlace, Vehicle, VehiclePricelist};
use Illuminate\Support\Facades\{DB, Schema, Storage};
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class DemoFleet
{
    public const MARKER = 'AMD_RENT_DEMO_V1:';

    private const VEHICLE_FIELDS = [
        'admin_organization_id', 'make', 'model', 'year', 'color', 'fuel_type',
        'transmission', 'seats', 'segment', 'mileage_current', 'default_pickup_location_id',
    ];
    private const PRICE_FIELDS = [
        'currency', 'base_daily_cents', 'weekend_pct', 'km_included_per_day',
        'extra_km_cents', 'deposit_cents', 'rounding', 'second_driver_daily_cents',
    ];
    private const SEASON_FIELDS = ['name', 'start_mmdd', 'end_mmdd', 'season_pct', 'weekend_pct_override', 'priority', 'is_active'];
    private const TIER_FIELDS = ['name', 'min_days', 'max_days', 'override_daily_cents', 'discount_pct', 'priority', 'is_active'];
    private const LOCATION_FIELDS = ['name', 'address_line', 'city', 'province', 'postal_code', 'country_code', 'lat', 'lng'];
    private const DELIVERY_FIELDS = ['custom_delivery_enabled', 'delivery_area', 'delivery_radius_km', 'custom_delivery_fee_cents'];
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** Every persistent marker includes the destination renter; it never changes a source record. */
    public static function marker(int $renter, string $kind, int|string $source): string
    {
        return self::MARKER.$renter.':'.$kind.':'.$source;
    }

    public static function plate(int $renter, int $vehicle): string
    {
        return 'DEMO'.strtoupper(substr(hash('sha256', $renter.':'.$vehicle), 0, 12));
    }

    /** This method only reads: even missing photos or ambiguous prices leave the database untouched. */
    public function preview(int $renterId, array $priceChoices = [], bool $withoutPhotos = false): array
    {
        $renter = Organization::query()->select('id', 'name', 'type', 'is_active', 'deleted_at')->find($renterId);
        if (!$renter || $renter->type !== 'renter' || !$renter->is_active || !preg_match('/^DEMO\b/iu', $renter->name)) {
            throw new RuntimeException('Serve un noleggiatore attivo il cui nome inizi con DEMO, creato dalla schermata Nuovo renter.');
        }

        $prefix = self::MARKER.$renterId.':';
        $this->assertUnusedRenter($renterId);
        $existing = Vehicle::withTrashed()->where('notes', 'like', $prefix.'vehicle:%')->count();
        if ($existing) {
            return ['already_created' => true, 'renter' => $renter->only(['id', 'name']), 'existing_vehicles' => $existing];
        }
        foreach (['locations' => 'organization_id', 'vehicle_assignments' => 'renter_org_id', 'vehicle_pricelists' => 'renter_org_id',
            'public_delivery_locations' => 'organization_id', 'vehicles' => 'admin_organization_id'] as $table => $column) {
            if (DB::table($table)->where($column, $renterId)->exists()) {
                throw new RuntimeException('Il noleggiatore contiene già sedi, auto, assegnazioni o listini. Usare un nuovo profilo DEMO vuoto.');
            }
        }

        $plan = [
            'already_created' => false, 'version' => 1,
            'renter' => $renter->only(['id', 'name', 'type', 'is_active']),
            'database' => ['driver' => DB::connection()->getDriverName(), 'host' => DB::connection()->getConfig('host'), 'name' => DB::connection()->getDatabaseName()],
            'without_photos' => $withoutPhotos, 'price_choices' => $priceChoices,
            'vehicles' => [], 'locations' => [], 'deliveries' => [], 'products' => [], 'errors' => [], 'warnings' => [],
        ];
        $organizations = Organization::query()->where('is_active', true)->whereKeyNot($renterId)
            ->orderBy('id')->get(['id', 'name', 'type'])->keyBy('id');
        $locations = Location::query()->whereIn('organization_id', $organizations->keys())
            ->where(fn ($q) => $q->whereNull('notes')->orWhere('notes', 'not like', self::MARKER.'%'))
            ->orderBy('id')->get();
        foreach ($locations as $location) {
            $plan['locations'][$location->id] = $location->only(self::LOCATION_FIELDS);
            if (!$location->city || !$location->country_code) {
                $plan['warnings'][] = "Sede {$location->id}: città o paese mancanti nell'originale; non saranno inventati.";
            }
        }
        if ($locations->isEmpty()) {
            $plan['errors'][] = 'Nessuna sede originale disponibile da copiare.';
        }
        $this->planDeliveries($plan, $locations->keyBy('id'), $organizations->keys()->all());

        $sources = Vehicle::query()->where('is_active', true)->whereIn('admin_organization_id', $organizations->keys())
            ->where(fn ($q) => $q->whereNull('notes')->orWhere('notes', 'not like', self::MARKER.'%'))
            ->with(['product', 'pricelists' => fn ($q) => $q->with(['seasons', 'tiers'])->orderBy('id'), 'media'])
            ->orderBy('id')->get();
        if ($sources->isEmpty()) {
            $plan['errors'][] = 'Nessuna auto attiva originale disponibile da copiare.';
        }
        foreach (array_diff(array_keys($priceChoices), $sources->modelKeys()) as $unknown) {
            $plan['errors'][] = "La scelta del listino riguarda un'auto non inclusa: {$unknown}.";
        }
        foreach ($sources as $source) {
            $row = [
                'source_id' => $source->id, 'source_plate' => $source->plate,
                'plate' => self::plate($renterId, $source->id), 'attributes' => $source->only(self::VEHICLE_FIELDS),
                'product_key' => null, 'price' => null, 'photos' => [],
            ];
            if (Vehicle::withTrashed()->where('plate', $row['plate'])->exists()) {
                $plan['errors'][] = "Targa dimostrativa già presente per l'auto {$source->id}: {$row['plate']}.";
            }
            $productLabel = Str::squish($source->product?->name ?: ($source->segment ?? ''));
            if ($productLabel !== '') {
                $key = hash('sha256', mb_strtolower($productLabel));
                $name = 'DEMO '.$renterId.' - '.$productLabel;
                if (mb_strlen($name) > 120) {
                    $plan['errors'][] = "Categoria troppo lunga per l'auto {$source->id}; occorre scegliere un'etichetta demo esplicita.";
                } elseif (DB::table('vehicle_products')->where('name_key', mb_strtolower($name))->exists()) {
                    $plan['errors'][] = "La categoria {$name} esiste già: nessuna categoria esistente verrà modificata.";
                }
                $plan['products'][$key] = ['name' => $name, 'name_key' => mb_strtolower($name),
                    'description' => self::marker($renterId, 'product', $key)];
                $row['product_key'] = $key;
            } else {
                $plan['warnings'][] = "Auto {$source->id}: categoria assente, la copia non avrà un segmento selezionabile.";
            }
            $price = $this->choosePrice($source, $priceChoices, $organizations->keys()->all(), $plan['errors']);
            if ($price) {
                $row['price'] = ['source_id' => $price->id, 'source_renter' => $price->renter_org_id,
                    'source_status' => $price->status, 'attributes' => $price->only(self::PRICE_FIELDS),
                    'seasons' => $price->seasons->sortBy('id')->map(fn ($s) => $s->only(self::SEASON_FIELDS))->values()->all(),
                    'tiers' => $price->tiers->sortBy('id')->map(fn ($t) => $t->only(self::TIER_FIELDS))->values()->all()];
                if ($price->status !== 'active' || !$price->active_flag) {
                    $plan['warnings'][] = "Auto {$source->id}: il listino {$price->id} scelto esplicitamente verrà attivato soltanto sulla copia.";
                }
            }
            if ($source->default_pickup_location_id && !isset($plan['locations'][$source->default_pickup_location_id])) {
                $plan['warnings'][] = "Auto {$source->id}: sede predefinita non copiabile, la copia avrà sede predefinita vuota.";
                $row['attributes']['default_pickup_location_id'] = null;
            }
            if (!$withoutPhotos) {
                $row['photos'] = $this->planPhotos($source, $plan['errors']);
            }
            if (!$row['photos']) {
                $plan['warnings'][] = "Auto {$source->id}: nessuna foto reale copiata; il portale userà l'immagine di riferimento, se disponibile.";
            }
            $plan['vehicles'][] = $row;
        }
        $plan['source_organizations'] = $organizations->map(fn ($org) => $org->only(['id', 'name', 'type']))->all();
        $plan['errors'] = array_values(array_unique($plan['errors']));
        $plan['warnings'] = array_values(array_unique($plan['warnings']));
        $plan['fingerprint'] = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        return $plan;
    }

    private function assertUnusedRenter(int $renter): void
    {
        foreach (['rentals', 'customers', 'public_bookings'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->where('organization_id', $renter)->exists()) {
                throw new RuntimeException('Il noleggiatore ha già clienti o prenotazioni: la procedura non opera su un profilo utilizzato.');
            }
        }
    }

    private function choosePrice(Vehicle $vehicle, array $choices, array $organizations, array &$errors): ?VehiclePricelist
    {
        $usable = $vehicle->pricelists->filter(fn ($p) => $p->currency === 'EUR' && $p->base_daily_cents !== null
            && $p->base_daily_cents >= 0 && in_array((int) $p->renter_org_id, $organizations, true));
        if (isset($choices[$vehicle->id])) {
            $chosen = $usable->firstWhere('id', $choices[$vehicle->id]);
            if (!$chosen) {
                $errors[] = "Auto {$vehicle->id}: il listino scelto non appartiene all'auto, non è in EUR o non è utilizzabile.";
            }
            return $chosen;
        }
        $active = $usable->filter(fn ($p) => $p->status === 'active' && $p->active_flag);
        if ($active->count() === 1) {
            return $active->first();
        }
        $candidates = $usable->map(fn ($p) => "{$p->id} (org {$p->renter_org_id}, {$p->status}, ".number_format($p->base_daily_cents / 100, 2, '.', '').' EUR/giorno)')->implode('; ');
        $errors[] = "Auto {$vehicle->id} ({$vehicle->make} {$vehicle->model}): ".($active->isEmpty() ? 'nessun listino attivo univoco' : 'più listini attivi')
            .'. Scegliere con --price='.$vehicle->id.':ID_LISTINO. Candidati: '.($candidates ?: 'nessuno; serve un listino prima della copia').'.';

        return null;
    }

    private function planDeliveries(array &$plan, $locations, array $organizations): void
    {
        $covered = [];
        $deliveries = PublicDeliveryLocation::query()->whereIn('organization_id', $organizations)->where('is_active', true)
            ->with('place')->orderBy('id')->get();
        foreach ($deliveries as $delivery) {
            $location = $locations->get($delivery->location_id);
            if (!$delivery->place || !$location || (int) $location->organization_id !== (int) $delivery->organization_id) {
                $plan['errors'][] = "Luogo servito {$delivery->id}: collegamenti originali incoerenti, da verificare.";
                continue;
            }
            $origin = $locations->get($delivery->delivery_origin_location_id);
            if ($delivery->custom_delivery_enabled && (!$origin || (int) $origin->organization_id !== (int) $delivery->organization_id
                || !is_numeric($origin->lat) || !is_numeric($origin->lng) || abs((float) $origin->lat) > 90 || abs((float) $origin->lng) > 180
                || (float) $delivery->delivery_radius_km <= 0 || $delivery->custom_delivery_fee_cents === null || $delivery->custom_delivery_fee_cents < 0)) {
                $plan['errors'][] = "Luogo servito {$delivery->id}: copertura o prezzo della consegna a domicilio incompleti.";
            }
            $plan['deliveries']['service'.$delivery->id] = [
                'source_location' => $location->id, 'source_origin' => $origin?->id,
                'place' => $delivery->place->only(['name', 'city', 'kind', 'address_line', 'country_code']),
                'attributes' => $delivery->only(self::DELIVERY_FIELDS),
            ];
            $covered[$location->id] = true;
        }
        foreach ($locations as $location) {
            if (!isset($covered[$location->id])) {
                $plan['deliveries']['location'.$location->id] = [
                    'source_location' => $location->id, 'source_origin' => null,
                    'place' => $location->only(['name', 'city', 'address_line', 'country_code']) + ['kind' => 'location'],
                    'attributes' => ['custom_delivery_enabled' => false, 'delivery_area' => null, 'delivery_radius_km' => null, 'custom_delivery_fee_cents' => null],
                ];
            }
        }
        foreach ($plan['deliveries'] as $key => &$delivery) {
            $delivery['place']['name'] = 'DEMO '.$plan['renter']['id'].' '.$key.' - '.Str::limit($delivery['place']['name'], 140, '');
            $delivery['place']['identity_key'] = PublicPickupPlace::identity($delivery['place']);
            if (DB::table('public_pickup_places')->where('identity_key', $delivery['place']['identity_key'])->exists()) {
                $plan['errors'][] = "Il punto demo {$key} esiste già; non verrà riutilizzato o modificato.";
            }
        }
        unset($delivery);
    }

    private function planPhotos(Vehicle $vehicle, array &$errors): array
    {
        $photos = [];
        foreach ($vehicle->getMedia('vehicle_photos')->sortBy('id') as $media) {
            if (!in_array($media->mime_type, self::IMAGE_TYPES, true)) {
                continue;
            }
            try {
                if (config('filesystems.disks.'.$media->disk.'.driver') !== 'local') {
                    throw new RuntimeException('Il comando copia soltanto foto su dischi locali.');
                }
                $path = $media->getPathRelativeToRoot();
                $this->assertRelativePath($path);
                if (str_contains($media->file_name, '/') || str_contains($media->file_name, '\\')) {
                    throw new RuntimeException('Nome del file non valido.');
                }
                $disk = Storage::disk($media->disk);
                $hash = $this->fileHash($media->disk, $path);
                if (!in_array($disk->mimeType($path), self::IMAGE_TYPES, true)) {
                    throw new RuntimeException('Il contenuto non è una foto supportata.');
                }
                $photos[] = ['source_id' => $media->id, 'path' => $path, 'sha256' => $hash,
                    'file_name' => $media->file_name, 'mime_type' => $disk->mimeType($path), 'disk' => $media->disk,
                    'size' => $disk->size($path), 'order_column' => $media->order_column];
            } catch (Throwable) {
                $errors[] = "Auto {$vehicle->id}, foto {$media->id}: file assente o non copiabile in questa installazione. Ripristinare i file oppure scegliere esplicitamente --without-photos.";
            }
        }

        return $photos;
    }

    private function assertRelativePath(string $path): void
    {
        if ($path === '' || str_contains($path, '\\') || str_contains($path, ':') || str_starts_with($path, '/')
            || preg_match('~(^|/)\.{1,2}(/|$)|[\x00-\x1f]~', $path)) {
            throw new RuntimeException('Percorso foto non valido.');
        }
    }

    private function fileHash(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);
        if (!is_resource($stream)) {
            throw new RuntimeException('Foto non leggibile.');
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    /** Lock only the demo organization. A changed preview or any failed insert rolls back the whole batch. */
    public function apply(int $renter, array $choices, bool $withoutPhotos, string $fingerprint): array
    {
        $writtenFiles = [];
        try {
            return DB::transaction(function () use ($renter, $choices, $withoutPhotos, $fingerprint, &$writtenFiles) {
                $locked = DB::table('organizations')->where('id', $renter)->lockForUpdate()->first(['id']);
                if (!$locked) {
                    throw new RuntimeException('Noleggiatore non trovato.');
                }
                $plan = $this->preview($renter, $choices, $withoutPhotos);
                if ($plan['already_created']) {
                    throw new RuntimeException('Copie demo già presenti: nessuna nuova copia creata.');
                }
                if ($plan['errors']) {
                    throw new RuntimeException('Anteprima con errori: nessuna copia creata.');
                }
                if (!preg_match('/^[a-f0-9]{64}$/', $fingerprint) || !hash_equals($plan['fingerprint'], $fingerprint)) {
                    throw new RuntimeException('Conferma assente o anteprima cambiata: ripetere il comando senza --apply e controllare il nuovo riepilogo.');
                }
                $now = now()->format('Y-m-d H:i:s');
                $products = $locations = $vehicles = [];
                foreach ($plan['products'] as $key => $product) {
                    $products[$key] = $this->insert('vehicle_products', $product, $now);
                }
                foreach ($plan['locations'] as $source => $attributes) {
                    $attributes['name'] = 'DEMO - '.Str::limit($attributes['name'], 180, '');
                    $locations[$source] = $this->insert('locations', $attributes + [
                        'organization_id' => $renter, 'notes' => self::marker($renter, 'location', $source),
                    ], $now);
                }
                foreach ($plan['deliveries'] as $delivery) {
                    $place = $this->insert('public_pickup_places', $delivery['place'], $now);
                    $this->insert('public_delivery_locations', $delivery['attributes'] + [
                        'organization_id' => $renter, 'public_pickup_place_id' => $place,
                        'location_id' => $locations[$delivery['source_location']], 'is_active' => true,
                        'delivery_origin_location_id' => $delivery['source_origin'] ? $locations[$delivery['source_origin']] : null,
                    ], $now);
                }
                foreach ($plan['vehicles'] as $row) {
                    $attributes = $row['attributes'];
                    $attributes['default_pickup_location_id'] = $locations[$attributes['default_pickup_location_id']] ?? null;
                    $attributes += ['plate' => $row['plate'], 'vin' => null, 'is_active' => true,
                        'vehicle_product_id' => $row['product_key'] ? $products[$row['product_key']] : null,
                        'notes' => self::marker($renter, 'vehicle', $row['source_id'])];
                    $vehicle = $this->insert('vehicles', $attributes, $now);
                    $vehicles[$row['source_id']] = $vehicle;
                    $this->insert('vehicle_assignments', [
                        'vehicle_id' => $vehicle, 'renter_org_id' => $renter, 'start_at' => $now, 'end_at' => null,
                        'status' => 'active', 'mileage_start' => $attributes['mileage_current'] ?? null,
                        'notes' => self::marker($renter, 'assignment', $row['source_id']),
                    ], $now);
                    $this->insert('vehicle_states', ['vehicle_id' => $vehicle, 'state' => 'assigned',
                        'started_at' => $now, 'ended_at' => null], $now);
                    $price = $this->insert('vehicle_pricelists', $row['price']['attributes'] + [
                        'vehicle_id' => $vehicle, 'renter_org_id' => $renter, 'name' => 'DEMO - Listino '.$row['price']['source_id'],
                        'version' => 1, 'status' => 'active', 'active_flag' => true, 'is_active' => true, 'published_at' => $now,
                        'notes' => self::marker($renter, 'price', $row['price']['source_id']),
                    ], $now);
                    foreach (['seasons' => 'vehicle_pricelist_seasons', 'tiers' => 'vehicle_pricelist_tiers'] as $kind => $table) {
                        foreach ($row['price'][$kind] as $child) {
                            $this->insert($table, $child + ['vehicle_pricelist_id' => $price], $now);
                        }
                    }
                    foreach ($row['photos'] as $photo) {
                        $this->copyPhoto($photo, $vehicle, $renter, $now, $writtenFiles);
                    }
                }

                return ['renter' => $renter, 'vehicles' => $vehicles, 'locations' => $locations,
                    'pricelists' => count($vehicles), 'deliveries' => count($plan['deliveries']), 'photos' => count($writtenFiles)];
            });
        } catch (Throwable $exception) {
            /** Files are outside the database transaction; remove only new exact paths owned by this failed attempt. */
            $cleanupFailed = false;
            foreach ($writtenFiles as [$disk, $path]) {
                try {
                    if (!Storage::disk($disk)->delete($path)) {
                        $cleanupFailed = true;
                    }
                } catch (Throwable) {
                    $cleanupFailed = true;
                }
            }
            if ($cleanupFailed) {
                throw new RuntimeException('Copia interrotta; alcune nuove foto demo non sono state rimosse. Verificare lo storage prima di riprovare.', previous: $exception);
            }
            throw $exception;
        }
    }

    private function insert(string $table, array $attributes, string $now): int
    {
        return DB::table($table)->insertGetId($attributes + ['created_at' => $now, 'updated_at' => $now]);
    }

    /** Copy original gallery images only, with fresh media IDs and no conversion jobs or model events. */
    private function copyPhoto(array $photo, int $vehicle, int $renter, string $now, array &$writtenFiles): void
    {
        $attributes = [
            'model_type' => (new Vehicle)->getMorphClass(), 'model_id' => $vehicle,
            'collection_name' => 'vehicle_photos', 'name' => 'DEMO - Foto '.$photo['source_id'],
            'file_name' => $photo['file_name'], 'mime_type' => $photo['mime_type'], 'disk' => $photo['disk'],
            'conversions_disk' => $photo['disk'], 'size' => $photo['size'], 'order_column' => $photo['order_column'],
            'manipulations' => '[]', 'responsive_images' => '[]', 'generated_conversions' => '[]',
            'custom_properties' => json_encode(['demo_source' => self::marker($renter, 'photo', $photo['source_id'])], JSON_THROW_ON_ERROR),
        ];
        if (Schema::hasColumn('media', 'uuid')) {
            $attributes['uuid'] = (string) Str::uuid();
        }
        $media = Media::findOrFail($this->insert('media', $attributes, $now));
        $path = $media->getPathRelativeToRoot();
        $this->assertRelativePath($path);
        $disk = Storage::disk($photo['disk']);
        if ($path === $photo['path'] || $disk->exists($path)) {
            throw new RuntimeException('Il percorso della nuova foto esiste già: nessun file esistente sarà sovrascritto.');
        }
        $stream = $disk->readStream($photo['path']);
        if (!is_resource($stream)) {
            throw new RuntimeException('La foto originale non è più leggibile.');
        }
        $writtenFiles[] = [$photo['disk'], $path];
        try {
            if (!$disk->put($path, $stream) || !hash_equals($photo['sha256'], $this->fileHash($photo['disk'], $path))) {
                throw new RuntimeException('Copia foto incompleta o file originale cambiato: operazione interrotta.');
            }
        } finally {
            fclose($stream);
        }
    }
}
