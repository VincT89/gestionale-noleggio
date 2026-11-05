<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MySqlQaGuard
{
    public static function check(bool $empty = false): string
    {
        $expected = (string) getenv('ADM_ERA_MYSQL_QA_DATABASE');
        $config = config('database.connections.mysql');
        if (!app()->environment('testing') || app()->configurationIsCached()
            || !preg_match('/^adm_era_qa_booking_[0-9]{8}_[0-9]{6}_[a-f0-9]{6}$/', $expected)
            || config('database.default') !== 'mysql' || $config['database'] !== $expected
            || !in_array($config['host'], ['localhost', '127.0.0.1', '::1'], true)
            || !empty($config['url']) || !empty($config['unix_socket']) || config('mail.default') !== 'array') {
            throw new RuntimeException('The booking test requires a fresh local database created by tests/mysql-checks.php.');
        }
        if (DB::selectOne('SELECT DATABASE() AS db')->db !== $expected) {
            throw new RuntimeException('Unexpected MySQL database.');
        }
        if ($empty && DB::select('SHOW TABLES') !== []) {
            throw new RuntimeException('The integration database must be empty; existing data will not be replaced.');
        }
        return $expected;
    }
}
