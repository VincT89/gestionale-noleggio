<?php

// No .env, application database, config cache, or shared compiled views.
$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$directory = $root.'/storage/framework/views/qa-interface-'.bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$environment = [
    'ADM_ERA_QA_NO_ENV' => '1', 'APP_ENV' => 'testing', 'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32)), 'APP_URL' => 'http://localhost',
    'APP_CONFIG_CACHE' => $directory.'/config-unused.php', 'APP_ROUTES_CACHE' => $directory.'/routes-unused.php',
    'VIEW_COMPILED_PATH' => $directory, 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'LOG_CHANNEL' => 'stderr', 'LOG_LEVEL' => 'critical', 'BCRYPT_ROUNDS' => '4', 'AMD_RENT_DOMAIN' => '',
    'AMD_RENT_STRIPE_SECRET' => '', 'AMD_RENT_STRIPE_WEBHOOK_SECRET' => '',
];
$process = new \Symfony\Component\Process\Process(
    [PHP_BINARY, $root.'/vendor/bin/phpunit', '--do-not-cache-result', '--colors=never', ...array_slice($argv, 1)],
    $root, $environment, null, 600
);
exit($process->run(fn ($type, $output) => print($output)));
