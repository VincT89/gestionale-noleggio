<?php

/** Run booking checks with disposable caches and SQLite fixtures, without loading any real environment file. */
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$relative = 'storage/framework/testing/booking-run-'.bin2hex(random_bytes(8));
$directory = $root.'/'.$relative;
mkdir($directory.'/views', 0700, true);
$environment = [
    'ADM_ERA_QA_NO_ENV' => '1', 'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_URL' => 'http://localhost',
    'APP_CONFIG_CACHE' => $relative.'/config-unused.php', 'APP_ROUTES_CACHE' => $relative.'/routes-unused.php',
    'APP_SERVICES_CACHE' => $relative.'/services.php', 'APP_PACKAGES_CACHE' => $relative.'/packages.php',
    'APP_EVENTS_CACHE' => $relative.'/events.php', 'VIEW_COMPILED_PATH' => $directory.'/views',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'LOG_CHANNEL' => 'stderr', 'LOG_LEVEL' => 'critical', 'BCRYPT_ROUNDS' => '4', 'AMD_RENT_DOMAIN' => '',
    'AMD_RENT_STRIPE_SECRET' => '', 'AMD_RENT_STRIPE_WEBHOOK_SECRET' => '', 'CARGOS_SEND_ENABLED' => 'false',
];
$arguments = array_slice($argv, 1) ?: ['--filter', 'AmdRentOperationsTest|PublicPickupDirectoryTest|PublicBookingTest|PublicSegmentJourneyTest|DeliveryBookingRecoveryTest'];
$process = new \Symfony\Component\Process\Process(
    [PHP_BINARY, $root.'/vendor/bin/phpunit', '--do-not-cache-result', '--colors=never', ...$arguments],
    $root, $environment, null, 600
);
try {
    $status = $process->run(fn ($type, $output) => print($output));
} finally {
    /** Only the exact random directory owned by this run may be removed. */
    $resolved = realpath($directory);
    $parent = realpath($root.'/storage/framework/testing');
    if ($resolved && $parent && dirname($resolved) === $parent && str_starts_with(basename($resolved), 'booking-run-')) {
        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($resolved);
    }
}
exit($status);
