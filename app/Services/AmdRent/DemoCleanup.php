<?php

namespace App\Services\AmdRent;

use App\Models\{Location, Organization, Vehicle, VehicleAssignment, VehiclePricelist, VehicleProduct};
use Illuminate\Database\Query\Builder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\{DB, Schema};
use RuntimeException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGeneratorFactory;
use Throwable;

final class DemoCleanup
{
    private const PENDING = 'amd_rent_demo_cleanup';
    private const DELETE_ORDER = ['public_rental_offers', 'amd_rent_options', 'vehicle_pricelist_seasons',
        'vehicle_pricelist_tiers', 'vehicle_pricelists', 'vehicle_assignments', 'vehicle_states',
        'public_delivery_locations', 'vehicles', 'vehicle_products', 'public_pickup_places', 'locations'];
    private array $columns = [];
    private array $foreignKeys = [];

    public function __construct(private Filesystem $filesystem) {}

    public function preview(int $renter, bool $deactivate = false): array
    {
        if ($renter < 1 || DB::connection()->getTablePrefix() !== ''
            || !in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException('Destinatario o configurazione database non supportati: nessuna modifica.');
        }
        $schema = DB::connection()->getDriverName() === 'sqlite' ? 'main' : DB::connection()->getDatabaseName();
        $tableDetails = Schema::getTables($schema);
        $tables = array_column($tableDetails, 'name');
        $this->columns = $this->foreignKeys = [];
        foreach ($tables as $table) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $table)) throw new RuntimeException('Nome tabella non gestibile: nessuna modifica.');
            $this->columns[$table] = Schema::getColumnListing($table);
            $this->foreignKeys[$table] = Schema::getForeignKeys($table);
        }
        foreach (['organizations', 'vehicles', 'locations', 'vehicle_products', 'vehicle_pricelists',
            'vehicle_assignments', 'vehicle_states', 'public_pickup_places', 'public_delivery_locations', 'media'] as $required) {
            if (!isset($this->columns[$required])) throw new RuntimeException('Schema incompleto: manca '.$required.'. Nessuna modifica.');
        }
        $organization = DB::table('organizations')->where('id', $renter)->first(['id', 'name', 'type', 'is_active', 'deleted_at', 'updated_at']);
        if (!$organization || $organization->type !== 'renter' || $organization->deleted_at !== null
            || !preg_match('/^DEMO\b/iu', $organization->name)) {
            throw new RuntimeException('Indicare l’ID di un noleggiatore DEMO riconosciuto. Gli altri profili sono esclusi.');
        }
        $plan = ['version' => 1, 'mode' => $deactivate ? 'deactivate' : 'purge', 'renter' => (array) $organization,
            'database' => $this->databaseIdentity(), 'application' => config('app.url'), 'ids' => [], 'rows' => [],
            'photos' => [], 'errors' => [], 'warnings' => [], 'schema' => $this->foreignKeys];
        foreach ([...self::DELETE_ORDER, 'media'] as $table) $this->put($plan, $table, []);
        $vehicles = $this->marked('vehicles', 'notes', DemoFleet::marker($renter, 'vehicle', ''));
        foreach ($vehicles as $vehicle) {
            $source = substr((string) $vehicle['notes'], strlen(DemoFleet::marker($renter, 'vehicle', '')));
            $plate = ctype_digit($source) && (int) $source > 0 ? DemoFleet::plate($renter, (int) $source)
                : (preg_match('/^insurance-preview-[1-9][0-9]*$/D', $source) ? 'DEMO'.strtoupper(substr(hash('sha256', $vehicle['notes']), 0, 12)) : null);
            if ($plate === null || $vehicle['plate'] !== $plate || !empty($vehicle['vin'])) {
                $plan['errors'][] = 'Auto '.$vehicle['id'].': targa, VIN o contrassegno non corrispondono alla copia demo.';
            }
        }
        $this->put($plan, 'vehicles', $vehicles);
        foreach (['locations' => ['notes', 'location'], 'vehicle_products' => ['description', 'product']] as $table => [$column, $kind]) {
            $rows = $this->marked($table, $column, DemoFleet::marker($renter, $kind, ''));
            foreach ($rows as $row) {
                $suffix = substr((string) $row[$column], strlen(DemoFleet::marker($renter, $kind, '')));
                if (($kind === 'location' && (!ctype_digit($suffix) || (int) $suffix < 1 || (int) $row['organization_id'] !== $renter))
                    || ($kind === 'product' && !preg_match('/^[a-f0-9]{64}$/D', $suffix))) {
                    $plan['errors'][] = $table.' #'.$row['id'].': contrassegno o appartenenza da verificare.';
                }
            }
            $this->put($plan, $table, $rows);
        }
        $vehicleIds = $plan['ids']['vehicles']; $locationIds = $plan['ids']['locations']; $productIds = $plan['ids']['vehicle_products'];
        $prices = $this->rows('vehicle_pricelists', fn (Builder $q) => $q->where('renter_org_id', $renter)->orWhereIn('vehicle_id', $vehicleIds));
        $this->put($plan, 'vehicle_pricelists', $this->owned($prices,
            fn ($row) => (int) $row['renter_org_id'] === $renter && in_array((int) $row['vehicle_id'], $vehicleIds, true), $plan, 'Listino'));
        $priceIds = $plan['ids']['vehicle_pricelists'];
        foreach (['vehicle_pricelist_seasons', 'vehicle_pricelist_tiers'] as $table) {
            $this->put($plan, $table, $this->rows($table, fn (Builder $q) => $q->whereIn('vehicle_pricelist_id', $priceIds)));
        }
        $assignments = $this->rows('vehicle_assignments', fn (Builder $q) => $q->where('renter_org_id', $renter)->orWhereIn('vehicle_id', $vehicleIds));
        $this->put($plan, 'vehicle_assignments', $this->owned($assignments,
            fn ($row) => (int) $row['renter_org_id'] === $renter && in_array((int) $row['vehicle_id'], $vehicleIds, true), $plan, 'Assegnazione'));
        $states = $this->rows('vehicle_states', fn (Builder $q) => $q->whereIn('vehicle_id', $vehicleIds));
        foreach ($states as $state) {
            if ($state['state'] !== 'assigned') $plan['errors'][] = 'Auto '.$state['vehicle_id'].': stato operativo diverso da quello della copia demo.';
        }
        $this->put($plan, 'vehicle_states', $states);
        $deliveries = $this->rows('public_delivery_locations', fn (Builder $q) => $q->where('organization_id', $renter)
            ->orWhereIn('location_id', $locationIds)->orWhereIn('delivery_origin_location_id', $locationIds));
        $deliveries = $this->owned($deliveries, fn ($row) => (int) $row['organization_id'] === $renter
            && in_array((int) $row['location_id'], $locationIds, true)
            && (empty($row['delivery_origin_location_id']) || in_array((int) $row['delivery_origin_location_id'], $locationIds, true)), $plan, 'Luogo servito');
        $this->put($plan, 'public_delivery_locations', $deliveries);
        $placeIds = array_map('intval', array_column($deliveries, 'public_pickup_place_id'));
        $places = $this->rows('public_pickup_places', function (Builder $q) use ($placeIds, $renter) {
            $q->whereIn('id', $placeIds)->orWhere(function (Builder $q) use ($renter) { $this->prefix($q, 'name', 'DEMO '.$renter.' '); });
        });
        foreach ($places as $place) {
            /** Source copies and Bari placeholders have distinct names and identity keys, even when their addresses resemble real places. */
            $copied = in_array((int) $place['id'], $placeIds, true)
                && preg_match('/^DEMO '.preg_quote((string) $renter, '/').' (?:service|location)[1-9][0-9]* - /u', $place['name']);
            $placeholder = false;
            foreach (\App\Domain\Rentals\PublicPickupDirectory::BARI_GROUPS as $group) {
                if ($place['name'] === 'DEMO '.$renter.' - '.$group['name'] && $place['address_line'] === DemoPickupGroups::ADDRESS
                    && $place['city'] === 'Bari' && $place['country_code'] === 'IT' && $place['kind'] === $group['kind']) $placeholder = true;
            }
            if ((!$copied && !$placeholder) || $place['identity_key'] !== \App\Models\PublicPickupPlace::identity($place)) {
                $plan['errors'][] = 'Punto di ritiro '.$place['id'].': origine dimostrativa non riconosciuta.';
            }
        }
        $this->put($plan, 'public_pickup_places', $places);
        $options = $this->rows('amd_rent_options', fn (Builder $q) => $q->where('organization_id', $renter)
            ->orWhereIn('vehicle_id', $vehicleIds)->orWhereIn('vehicle_product_id', $productIds));
        $this->put($plan, 'amd_rent_options', $this->owned($options, fn ($row) => (int) $row['organization_id'] === $renter
            && (empty($row['vehicle_id']) || in_array((int) $row['vehicle_id'], $vehicleIds, true))
            && (empty($row['vehicle_product_id']) || in_array((int) $row['vehicle_product_id'], $productIds, true)), $plan, 'Protezione o accessorio'));
        $offers = $this->rows('public_rental_offers', fn (Builder $q) => $q->where('organization_id', $renter)
            ->orWhereIn('vehicle_id', $vehicleIds)->orWhereIn('pricelist_id', $priceIds)->orWhereIn('location_id', $locationIds));
        $this->put($plan, 'public_rental_offers', $this->owned($offers, fn ($row) => (int) $row['organization_id'] === $renter
            && in_array((int) $row['vehicle_id'], $vehicleIds, true) && in_array((int) $row['pricelist_id'], $priceIds, true)
            && in_array((int) $row['location_id'], $locationIds, true), $plan, 'Offerta pubblica'));
        $this->photos($plan, $deactivate);
        if (!$deactivate) $this->sharedPhotoFolders($plan);
        $this->references($plan);
        $this->historicalSnapshots($plan);
        $this->databaseSafety($plan, $tableDetails);
        $plan['errors'] = array_values(array_unique($plan['errors']));
        $plan['warnings'][] = 'Il profilo DEMO viene conservato e disattivato. Utenti e account amministrativi vengono conservati.';
        $plan['warnings'][] = 'Le fotografie sono verificate solo nello storage di questa installazione. Eventuali copie nello storage dell’altro dominio richiedono una verifica separata.';
        $plan['empty'] = !(bool) $organization->is_active && ($deactivate || array_sum(array_map('count', $plan['ids'])) === 0);
        $plan['fingerprint'] = $this->hash(Arr::except($plan, ['warnings', 'fingerprint']));
        return $plan;
    }

    public function apply(int $renter, string $fingerprint, bool $deactivate = false): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $fingerprint)) throw new RuntimeException('Serve l’impronta completa dell’anteprima: nessuna modifica.');
        $plan = DB::transaction(function () use ($renter, $fingerprint, $deactivate) {
            DB::table('organizations')->where('id', $renter)->lockForUpdate()->first();
            $before = $this->preview($renter, $deactivate);
            foreach ($before['ids'] as $table => $ids) {
                foreach (array_chunk($ids, 200) as $chunk) DB::table($table)->whereIn('id', $chunk)->orderBy('id')->lockForUpdate()->get();
            }
            $plan = $this->preview($renter, $deactivate);
            if ($plan['errors']) throw new RuntimeException('Pulizia bloccata: risolvere tutte le voci segnalate nell’anteprima. Nessuna modifica.');
            if (!hash_equals($plan['fingerprint'], $fingerprint)) throw new RuntimeException('L’anteprima, i file o l’ambiente sono cambiati. Ripetere l’anteprima. Nessuna modifica.');
            if (!$deactivate) {
                /** Durable signed manifests keep unfinished photo cleanup retryable after the data transaction has committed. */
                foreach ($plan['photos'] as $photo) {
                    $properties = $this->properties($plan['rows']['media'][$photo['id']]['custom_properties']);
                    $properties[self::PENDING] = $photo['manifest'];
                    DB::table('media')->where('id', $photo['id'])->update(['custom_properties' => json_encode($properties, JSON_THROW_ON_ERROR)]);
                }
                foreach (self::DELETE_ORDER as $table) $this->deleteRows($table, $plan['ids'][$table]);
            }
            DB::table('organizations')->where('id', $renter)->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]);
            return $plan;
        });
        if (!$deactivate && $plan['ids']['media'] !== []) {
            try {
                $this->finishPhotos($renter, $plan['ids']['media']);
            } catch (Throwable) {
                throw new RuntimeException('I dati demo sono stati rimossi e il profilo è disattivato; la pulizia di alcune foto resta in sospeso. I riferimenti sono conservati. Ripetere l’anteprima sullo stesso sito prima di riprovare.');
            }
        }
        return ['mode' => $plan['mode'], 'counts' => array_map('count', $plan['ids']), 'renter' => $renter];
    }

    private function references(array &$plan): void
    {
        $roots = $plan['ids'];
        $roots['organizations'] = [(int) $plan['renter']['id']];
        foreach ($plan['photos'] as $photo) $roots['vehicles'][] = $photo['vehicle_id'];
        $roots['vehicles'] = array_values(array_unique($roots['vehicles']));
        $aliases = ['organization_id' => 'organizations', 'renter_org_id' => 'organizations', 'agency_organization_id' => 'organizations',
            'admin_organization_id' => 'organizations', 'vehicle_id' => 'vehicles', 'vehicle_product_id' => 'vehicle_products',
            'product_id' => 'vehicle_products', 'location_id' => 'locations', 'default_pickup_location_id' => 'locations',
            'pickup_location_id' => 'locations', 'return_location_id' => 'locations', 'delivery_origin_location_id' => 'locations',
            'public_pickup_place_id' => 'public_pickup_places', 'public_delivery_location_id' => 'public_delivery_locations',
            'pricelist_id' => 'vehicle_pricelists', 'vehicle_pricelist_id' => 'vehicle_pricelists', 'assignment_id' => 'vehicle_assignments',
            'vehicle_assignment_id' => 'vehicle_assignments', 'vehicle_state_id' => 'vehicle_states', 'option_id' => 'amd_rent_options',
            'amd_rent_option_id' => 'amd_rent_options', 'public_rental_offer_id' => 'public_rental_offers',
            'media_id' => 'media', 'first_media_id' => 'media', 'current_media_id' => 'media', 'last_sent_media_id' => 'media'];
        $morphs = [Vehicle::class => 'vehicles', Location::class => 'locations', Organization::class => 'organizations',
            VehicleProduct::class => 'vehicle_products', VehiclePricelist::class => 'vehicle_pricelists', VehicleAssignment::class => 'vehicle_assignments'];
        foreach ($this->columns as $table => $columns) {
            $references = array_intersect_key($aliases, array_flip($columns));
            foreach ($this->foreignKeys[$table] as $foreign) {
                if (!isset($roots[$foreign['foreign_table']]) || $roots[$foreign['foreign_table']] === []) continue;
                if (count($foreign['columns']) !== 1 || $foreign['foreign_columns'] !== ['id']) {
                    $plan['errors'][] = 'Relazione personalizzata in '.$table.': verifica manuale necessaria.';
                    continue;
                }
                $references[$foreign['columns'][0]] = $foreign['foreign_table'];
            }
            foreach ($references as $column => $root) {
                if (empty($roots[$root]) || ($table === 'users' && $root === 'organizations')) continue;
                $query = DB::table($table)->whereIn($column, $roots[$root]);
                if (in_array('id', $columns, true) && !empty($plan['ids'][$table])) $query->whereNotIn('id', $plan['ids'][$table]);
                if ($query->exists()) $plan['errors'][] = 'Collegamenti esterni o dati operativi in '.$table.'.'.$column.'. Nessun record sarà cancellato.';
            }
            if (in_array('model_type', $columns, true) && in_array('model_id', $columns, true)) {
                foreach ($morphs as $class => $root) {
                    if (empty($roots[$root])) continue;
                    $types = array_unique([$class, (new $class)->getMorphClass()]);
                    $query = DB::table($table)->whereIn('model_type', $types)->whereIn('model_id', $roots[$root]);
                    if (in_array('id', $columns, true) && !empty($plan['ids'][$table])) $query->whereNotIn('id', $plan['ids'][$table]);
                    if ($query->exists()) $plan['errors'][] = 'Documenti o collegamenti non demo in '.$table.' per '.$root.'.';
                }
            }
        }
    }

    private function historicalSnapshots(array &$plan): void
    {
        foreach (['public_bookings' => 'quote_snapshot', 'amd_rent_enquiries' => 'booking_context'] as $table => $column) {
            if (!in_array($column, $this->columns[$table] ?? [], true)) continue;
            DB::table($table)->select(['id', $column])->orderBy('id')->chunkById(200, function ($rows) use (&$plan, $table, $column) {
                foreach ($rows as $row) {
                    $snapshot = json_decode((string) $row->{$column}, true);
                    if (!is_array($snapshot)) continue;
                    $car = $table === 'amd_rent_enquiries' ? ($snapshot['car'] ?? []) : $snapshot;
                    $references = ['vehicles' => [$car['vehicle_id'] ?? null], 'vehicle_pricelists' => [$snapshot['pricelist'] ?? null, $car['pricelist_id'] ?? null, $car['id'] ?? null],
                        'vehicle_products' => [$car['product_id'] ?? null], 'amd_rent_options' => array_column($car['selected_extras'] ?? [], 'id')];
                    foreach ($references as $target => $ids) {
                        $targetIds = $plan['ids'][$target];
                        if ($target === 'vehicles') $targetIds = array_merge($targetIds, array_column($plan['photos'], 'vehicle_id'));
                        if (array_intersect(array_filter($ids), $targetIds)) {
                            $plan['errors'][] = $table.' #'.$row->id.': dati demo richiamati da una prenotazione o richiesta storica.';
                            break;
                        }
                    }
                }
            });
        }
    }

    private function databaseSafety(array &$plan, array $tables): void
    {
        $targets = $plan['mode'] === 'deactivate' ? [] : array_keys(array_filter($plan['ids']));
        $targets[] = 'organizations';
        if (DB::connection()->getDriverName() === 'sqlite') {
            $triggers = DB::table('sqlite_master')->where('type', 'trigger')->whereIn('tbl_name', $targets)->pluck('name');
        } else {
            foreach ($tables as $table) {
                if (in_array($table['name'], $targets, true) && strcasecmp((string) $table['engine'], 'InnoDB') !== 0) {
                    $plan['errors'][] = $table['name'].': motore non transazionale, pulizia automatica bloccata.';
                }
            }
            $triggers = DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
                ->whereIn('EVENT_OBJECT_TABLE', $targets)->pluck('TRIGGER_NAME');
        }
        if ($triggers->isNotEmpty()) $plan['errors'][] = 'Trigger personalizzati sulle tabelle da modificare: verifica manuale necessaria.';
    }

    private function photos(array &$plan, bool $deactivate): void
    {
        $renter = (int) $plan['renter']['id'];
        $types = array_unique([Vehicle::class, (new Vehicle)->getMorphClass()]);
        $records = Media::query()->where(function ($query) use ($plan, $types, $renter) {
            $query->where(fn ($q) => $q->whereIn('model_type', $types)->whereIn('model_id', $plan['ids']['vehicles']))
                ->orWhere('custom_properties', 'like', '%'.DemoFleet::marker($renter, 'photo', '').'%');
        })->orderBy('id')->get();
        $owned = [];
        foreach ($records as $media) {
            $source = $media->getCustomProperty('demo_source');
            $prefix = DemoFleet::marker($renter, 'photo', '');
            $attached = in_array((int) $media->model_id, $plan['ids']['vehicles'], true) && in_array($media->model_type, $types, true);
            if (!$attached && (!is_string($source) || !str_starts_with($source, $prefix))) continue;
            if (!is_string($source) || !preg_match('/^'.preg_quote($prefix, '/').'[1-9][0-9]*$/D', $source)
                || $media->collection_name !== 'vehicle_photos' || !in_array($media->model_type, $types, true)) {
                $plan['errors'][] = 'Foto '.$media->id.': provenienza demo non verificabile.';
                continue;
            }
            $pending = $media->getCustomProperty(self::PENDING);
            try {
                if (!$attached && (DB::table('vehicles')->where('id', $media->model_id)->exists() || !is_array($pending))) {
                    throw new RuntimeException('Foto demo collegata a un’auto non riconosciuta.');
                }
                $manifest = $deactivate ? null : ($pending ? $this->validatePending($media, $pending, $renter) : $this->photoManifest($media, $renter));
                $owned[] = $media->getAttributes();
                if ($manifest) $plan['photos'][] = ['id' => (int) $media->id, 'vehicle_id' => (int) $media->model_id, 'manifest' => $manifest];
            } catch (Throwable $exception) {
                $plan['errors'][] = 'Foto '.$media->id.': '.(get_class($exception) === RuntimeException::class ? $exception->getMessage() : 'storage non verificabile.');
            }
        }
        $this->put($plan, 'media', $owned);
    }

    private function photoManifest(Media $media, int $renter): array
    {
        $generator = PathGeneratorFactory::create($media);
        $prefix = $media->id.'/';
        if ($generator->getPath($media) !== $prefix || !str_starts_with($generator->getPathForConversions($media), $prefix)
            || !str_starts_with($generator->getPathForResponsiveImages($media), $prefix)
            || basename($media->file_name) !== $media->file_name || preg_match('~[\\\\/:\x00-\x1f]~', $media->file_name)
            || in_array($media->file_name, ['', '.', '..'], true)) {
            throw new RuntimeException('percorso media non standard o non sicuro.');
        }
        $disks = array_values(array_unique([$media->disk, $media->conversions_disk ?: $media->disk]));
        $roots = $files = $directories = [];
        foreach ($disks as $disk) {
            $roots[$disk] = $this->localRoot($disk);
            [$diskFiles, $diskDirectories] = $this->scan($disk, $prefix);
            array_push($files, ...$diskFiles); array_push($directories, ...$diskDirectories);
        }
        if (!array_filter($files, fn ($file) => $file['disk'] === $media->disk && $file['path'] === $prefix.$media->file_name)) {
            throw new RuntimeException('file principale assente: verificare il sito e lo storage prima della pulizia.');
        }
        $responsiveNames = [];
        $responsive = $media->responsive_images ?? [];
        array_walk_recursive($responsive, function ($value) use (&$responsiveNames) {
            if (is_string($value) && !str_starts_with($value, 'data:')) $responsiveNames[] = basename(parse_url($value, PHP_URL_PATH) ?: $value);
        });
        foreach ($files as $file) {
            $registered = $file['disk'] === $media->disk && $file['path'] === $prefix.$media->file_name;
            foreach ($media->generated_conversions ?? [] as $conversion => $generated) {
                $stem = pathinfo($media->file_name, PATHINFO_FILENAME).'-'.$conversion;
                if ($generated && $file['disk'] === ($media->conversions_disk ?: $media->disk)
                    && preg_match('~^'.preg_quote($generator->getPathForConversions($media).$stem, '~').'\.(?:jpe?g|png|webp|gif|avif)$~iD', $file['path'])) $registered = true;
            }
            if ($file['disk'] === ($media->conversions_disk ?: $media->disk)
                && str_starts_with($file['path'], $generator->getPathForResponsiveImages($media))
                && in_array(basename($file['path']), $responsiveNames, true)) $registered = true;
            if (!$registered) throw new RuntimeException('file non registrato nella cartella della foto: verifica manuale necessaria.');
        }
        $manifest = ['version' => 1, 'renter' => $renter, 'media' => (int) $media->id, 'vehicle' => (int) $media->model_id,
            'files' => $files, 'directories' => $directories, 'context' => $this->hash([$this->databaseIdentity(), config('app.url'), $roots])];
        $manifest['signature'] = $this->sign($manifest);
        return $manifest;
    }

    private function sharedPhotoFolders(array &$plan): void
    {
        if ($plan['photos'] === []) return;
        $targets = [];
        foreach ($plan['photos'] as $photo) {
            foreach ($photo['manifest']['files'] as $file) {
                $targets[] = $this->localRoot($file['disk']).'/'.$photo['id'].'/';
            }
        }
        $targets = array_values(array_unique($targets));
        $referenceRoot = config('filesystems.disks.car_models.root');
        if (is_string($referenceRoot) && ($resolved = realpath($referenceRoot))) {
            $referenceRoot = rtrim(str_replace('\\', '/', $resolved), '/').'/';
            foreach ($targets as $target) {
                $left = PHP_OS_FAMILY === 'Windows' ? strtolower($referenceRoot) : $referenceRoot;
                $right = PHP_OS_FAMILY === 'Windows' ? strtolower($target) : $target;
                if (str_starts_with($left, $right) || str_starts_with($right, $left)) {
                    $plan['errors'][] = 'Cartella condivisa con le immagini di riferimento dei modelli: pulizia automatica bloccata.';
                }
            }
        }
        /** A separate disk may point inside a demo folder; media IDs alone cannot prove physical file ownership. */
        foreach (Media::query()->whereNotIn('id', $plan['ids']['media'])->orderBy('id')->cursor() as $other) {
            foreach (array_unique([$other->disk, $other->conversions_disk ?: $other->disk]) as $disk) {
                $config = config('filesystems.disks.'.$disk);
                if (($config['driver'] ?? null) !== 'local' || !is_dir($config['root'] ?? '')) continue;
                try {
                    $generator = PathGeneratorFactory::create($other);
                    foreach ([$generator->getPath($other), $generator->getPathForConversions($other), $generator->getPathForResponsiveImages($other)] as $relative) {
                        $path = rtrim($this->safePath($disk, $relative), '/').'/';
                        foreach ($targets as $target) {
                            $left = PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
                            $right = PHP_OS_FAMILY === 'Windows' ? strtolower($target) : $target;
                            if (str_starts_with($left, $right) || str_starts_with($right, $left)) {
                                $plan['errors'][] = 'Cartella foto condivisa con il media #'.$other->id.': pulizia automatica bloccata.';
                            }
                        }
                    }
                } catch (Throwable) {
                    $plan['errors'][] = 'Separazione delle cartelle foto non verificabile per il media #'.$other->id.'.';
                }
            }
        }
    }

    private function validatePending(Media $media, array $manifest, int $renter): array
    {
        if (($manifest['version'] ?? null) !== 1 || ($manifest['renter'] ?? null) !== $renter
            || ($manifest['media'] ?? null) !== (int) $media->id || ($manifest['vehicle'] ?? null) !== (int) $media->model_id
            || !is_string($manifest['signature'] ?? null) || !hash_equals($this->sign(Arr::except($manifest, ['signature'])), $manifest['signature'])) {
            throw new RuntimeException('riferimento di pulizia in sospeso non valido.');
        }
        $roots = [];
        foreach (array_values(array_unique([$media->disk, $media->conversions_disk ?: $media->disk])) as $disk) $roots[$disk] = $this->localRoot($disk);
        if (!hash_equals($manifest['context'], $this->hash([$this->databaseIdentity(), config('app.url'), $roots]))) {
            throw new RuntimeException('ripetere la pulizia sullo stesso sito e storage dell’operazione precedente.');
        }
        $expected = [];
        foreach ($manifest['files'] as $file) $expected[$file['disk'].':'.$file['path']] = $file;
        foreach ($roots as $disk => $root) {
            [$files, $directories] = $this->scan($disk, $media->id.'/');
            foreach ($files as $file) {
                $previous = $expected[$file['disk'].':'.$file['path']] ?? null;
                if (!$previous || !hash_equals($this->hash($previous), $this->hash($file))) throw new RuntimeException('foto in sospeso modificata o contenuto aggiunto: verifica manuale necessaria.');
            }
            foreach ($directories as $directory) {
                if (!in_array($directory, $manifest['directories'])) throw new RuntimeException('cartella foto aggiunta dopo l’anteprima.');
            }
        }
        return $manifest;
    }

    private function finishPhotos(int $renter, array $ids): void
    {
        DB::transaction(function () use ($renter, $ids) {
            DB::table('organizations')->where('id', $renter)->lockForUpdate()->first();
            DB::table('media')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $plan = $this->preview($renter);
            if ($plan['errors']) throw new RuntimeException('Pulizia foto bloccata da nuovi collegamenti.');
            foreach ($plan['photos'] as $photo) {
                if (!in_array($photo['id'], $ids, true) || DB::table('vehicles')->where('id', $photo['vehicle_id'])->exists()) {
                    throw new RuntimeException('Foto ancora collegata a un’auto.');
                }
                foreach ($photo['manifest']['files'] as $file) {
                    $path = $this->safePath($file['disk'], $file['path']);
                    if (!file_exists($path)) continue;
                    if (!is_file($path) || filesize($path) !== $file['size'] || !hash_equals($file['sha256'], hash_file('sha256', $path))) {
                        throw new RuntimeException('File cambiato durante la pulizia.');
                    }
                    if (!$this->removeFile($path)) throw new RuntimeException('File non eliminabile.');
                }
                $directories = $photo['manifest']['directories'];
                usort($directories, fn ($a, $b) => strlen($b['path']) <=> strlen($a['path']));
                foreach ($directories as $directory) {
                    $path = $this->safePath($directory['disk'], $directory['path']);
                    if (is_dir($path) && !@rmdir($path)) throw new RuntimeException('Cartella non vuota o non eliminabile.');
                }
                DB::table('media')->where('id', $photo['id'])->delete();
            }
        });
    }

    protected function removeFile(string $path): bool
    {
        return $this->filesystem->delete($path);
    }

    protected function deleteRows(string $table, array $ids): void
    {
        foreach (array_chunk($ids, 200) as $chunk) DB::table($table)->whereIn('id', $chunk)->delete();
    }

    private function scan(string $disk, string $prefix): array
    {
        $directory = $this->safePath($disk, $prefix);
        if (!file_exists($directory)) return [[], []];
        if (!is_dir($directory)) throw new RuntimeException('cartella media non valida.');
        $files = []; $directories = [['disk' => $disk, 'path' => rtrim($prefix, '/')]];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $relative = rtrim($prefix, '/').'/'.str_replace('\\', '/', substr($entry->getPathname(), strlen($directory) + 1));
            $path = $this->safePath($disk, $relative);
            if ($entry->isLink() || (!is_dir($path) && !is_file($path))) throw new RuntimeException('collegamento simbolico o file speciale nella cartella foto.');
            if ($entry->isDir()) $directories[] = ['disk' => $disk, 'path' => $relative];
            else {
                $size = @filesize($path); $hash = @hash_file('sha256', $path);
                if ($size === false || $hash === false) throw new RuntimeException('foto non leggibile: verifica manuale necessaria.');
                $files[] = ['disk' => $disk, 'path' => $relative, 'size' => $size, 'sha256' => $hash];
            }
        }
        usort($files, fn ($a, $b) => strcmp($a['path'], $b['path']));
        usort($directories, fn ($a, $b) => strcmp($a['path'], $b['path']));
        return [$files, $directories];
    }

    private function localRoot(string $disk): string
    {
        $configuration = config('filesystems.disks.'.$disk);
        if (!is_array($configuration) || ($configuration['driver'] ?? null) !== 'local' || !is_string($configuration['root'] ?? null)) {
            throw new RuntimeException('sono supportate soltanto fotografie su uno storage locale verificabile.');
        }
        $root = realpath($configuration['root']);
        if ($root === false || !is_dir($root)) throw new RuntimeException('storage locale assente. Nessuna cartella viene creata dall’anteprima.');
        return rtrim(str_replace('\\', '/', $root), '/');
    }

    private function safePath(string $disk, string $relative): string
    {
        if ($relative === '' || str_contains($relative, '\\') || str_contains($relative, ':') || str_starts_with($relative, '/')
            || preg_match('~(^|/)\.{1,2}(/|$)|[\x00-\x1f]~', $relative)) throw new RuntimeException('percorso foto non valido.');
        $root = $this->localRoot($disk);
        $current = $root;
        foreach (explode('/', trim($relative, '/')) as $part) {
            $current .= '/'.$part;
            clearstatcache(true, $current);
            if (is_link($current)) throw new RuntimeException('collegamento simbolico nel percorso foto.');
            if (file_exists($current)) {
                $resolved = realpath($current);
                $expected = PHP_OS_FAMILY === 'Windows' ? strtolower($current) : $current;
                $actual = PHP_OS_FAMILY === 'Windows' ? strtolower(str_replace('\\', '/', (string) $resolved)) : $resolved;
                if ($actual !== $expected) throw new RuntimeException('percorso foto diverso dalla cartella prevista.');
            }
        }
        return $current;
    }

    private function marked(string $table, string $column, string $prefix): array
    {
        /** MySQL collations can ignore case or accents; the ownership marker must also match byte for byte. */
        return array_values(array_filter($this->rows($table, fn (Builder $q) => $this->prefix($q, $column, $prefix)),
            fn ($row) => str_starts_with((string) $row[$column], $prefix)));
    }

    private function prefix(Builder $query, string $column, string $prefix): Builder
    {
        /** SQL LIKE treats underscores in our marker as wildcards; compare the literal prefix instead. */
        return $query->whereRaw('SUBSTR('.DB::connection()->getQueryGrammar()->wrap($column).', 1, ?) = ?', [strlen($prefix), $prefix]);
    }

    private function rows(string $table, callable $where): array
    {
        if (!isset($this->columns[$table])) return [];
        return DB::table($table)->where($where)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function put(array &$plan, string $table, array $rows): void
    {
        $plan['rows'][$table] = array_column($rows, null, 'id');
        $plan['ids'][$table] = array_map('intval', array_keys($plan['rows'][$table]));
    }

    private function owned(array $rows, callable $valid, array &$plan, string $label): array
    {
        $owned = [];
        foreach ($rows as $row) {
            if ($valid($row)) $owned[] = $row;
            else $plan['errors'][] = $label.' #'.$row['id'].': collegamento esterno o non dimostrativo. Nessuna modifica.';
        }
        return $owned;
    }

    private function properties(string|array $value): array
    {
        return is_array($value) ? $value : json_decode($value, true, 32, JSON_THROW_ON_ERROR);
    }

    private function databaseIdentity(): array
    {
        return ['driver' => DB::connection()->getDriverName(), 'database' => DB::connection()->getDatabaseName(),
            'host' => DB::connection()->getConfig('host'), 'port' => DB::connection()->getConfig('port')];
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode(Arr::sortRecursive($value), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function sign(array $manifest): string
    {
        $key = config('app.key');
        if (!is_string($key) || $key === '') throw new RuntimeException('Chiave applicativa assente: operazione bloccata.');
        return hash_hmac('sha256', $this->hash($manifest), $key);
    }
}
