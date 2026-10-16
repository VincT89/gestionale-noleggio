<?php

/** Run only isolated fixture tests; never load the application's .env or use a configured production database. */
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$relativeDirectory = 'storage/framework/testing/demo-run-'.bin2hex(random_bytes(8));
$directory = $root.'/'.$relativeDirectory;
mkdir($directory.'/views', 0700, true);
$environment = [
    'ADM_ERA_QA_NO_ENV' => '1', 'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_URL' => 'http://localhost',
    'APP_CONFIG_CACHE' => $relativeDirectory.'/config-unused.php', 'APP_ROUTES_CACHE' => $relativeDirectory.'/routes-unused.php',
    'APP_SERVICES_CACHE' => $relativeDirectory.'/services.php', 'APP_PACKAGES_CACHE' => $relativeDirectory.'/packages.php',
    'APP_EVENTS_CACHE' => $relativeDirectory.'/events.php', 'VIEW_COMPILED_PATH' => $directory.'/views',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'LOG_CHANNEL' => 'stderr', 'LOG_LEVEL' => 'critical', 'BCRYPT_ROUNDS' => '4', 'AMD_RENT_DOMAIN' => '',
    'AMD_RENT_STRIPE_SECRET' => '', 'AMD_RENT_STRIPE_WEBHOOK_SECRET' => '', 'CARGOS_SEND_ENABLED' => 'false',
];
$process = new \Symfony\Component\Process\Process(
    [PHP_BINARY, $root.'/vendor/bin/phpunit', '--do-not-cache-result', '--colors=never',
        $root.'/tests/Feature/AmdRentDemoFleetTest.php'],
    $root, $environment, null, 180
);
$status = $process->run(fn ($type, $output) => print($output));
/** The runner owns this random directory; cleanup must never expand to the project's shared storage. */
if (str_starts_with($directory, $root.'/storage/framework/testing/demo-run-')) {
    (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($directory);
}
exit($status);
