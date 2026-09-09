<?php

use App\Domain\Rentals\PublicBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\{Date, DB, Http};
use Illuminate\Validation\ValidationException;
use Tests\Support\MySqlQaGuard;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
MySqlQaGuard::check();
Http::preventStrayRequests();
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$now = CarbonImmutable::parse($input['now']);
Date::setTestNow($now);
CarbonImmutable::setTestNow($now);
$reported = false;
DB::connection()->beforeExecuting(function ($query) use (&$reported) {
    if (!$reported && str_contains(strtolower($query), 'for update')) {
        $reported = true;
        echo "WAITING_FOR_LOCK\n";
        flush();
    }
});
try {
    $booking = app(PublicBookingService::class)->reserve($input['intent'], $input['contact']);
    echo 'RESULT '.json_encode(['status' => 'confirmed', 'id' => $booking->id], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (ValidationException $exception) {
    echo 'RESULT '.json_encode(['status' => 'unavailable', 'errors' => $exception->errors()], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
    exit(2);
}
