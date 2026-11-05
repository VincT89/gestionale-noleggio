<?php

// Run only against databases created by this process on the local MySQL server.
// Credentials stay in memory and are passed to child processes as environment values.
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

$root = dirname(__DIR__);
if (file_exists($root.'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Clear the local configuration cache before isolated MySQL checks.');
}
chdir($root);
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
    'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array'] as $key => $value) {
    putenv($key.'='.$value);
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$config = config('database.connections.mysql');
if (!in_array($config['host'], ['localhost', '127.0.0.1', '::1'], true) || !empty($config['url']) || !empty($config['unix_socket'])) {
    throw new RuntimeException('Only an explicit loopback MySQL connection is allowed.');
}
$pdo = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';charset=utf8mb4',
    $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$mode = $argv[1] ?? 'suite';
if (!in_array($mode, ['suite', 'booking'], true)) throw new RuntimeException('Choose suite or booking.');
$database = 'adm_era_qa_'.$mode.'_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(3));
$pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$environment = [
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://localhost',
    'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
    'DB_DATABASE' => $database, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'DB_SOCKET' => '',
    'ADM_ERA_MYSQL_QA_DATABASE' => $database, 'ACTIVITY_LOGGER_DB_CONNECTION' => 'mysql',
    'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'BCRYPT_ROUNDS' => '4', 'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
];
$arguments = [PHP_BINARY, $root.'/vendor/bin/phpunit', '--colors=never'];
if ($mode === 'booking') $arguments[] = $root.'/tests/Integration/PublicBookingMySqlTest.php';
$report = $root.'/storage/logs/'.$database.'.log';
file_put_contents($report, json_encode(['database' => $database, 'host' => $config['host'],
    'mysql_version' => $pdo->query('SELECT VERSION()')->fetchColumn(), 'mode' => $mode], JSON_THROW_ON_ERROR).PHP_EOL);
echo 'Isolated MySQL database: '.$database.PHP_EOL;
$process = new Process($arguments, $root, $environment, null, 600);
$exit = $process->run(function ($type, $output) use ($report) {
    file_put_contents($report, $output, FILE_APPEND);
    echo $output;
});
echo 'Report: '.$report.PHP_EOL;
echo 'The isolated database has been retained for inspection.'.PHP_EOL;
exit($exit);
