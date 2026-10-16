<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        if (getenv('ADM_ERA_QA_NO_ENV') !== '1') {
            return parent::createApplication();
        }

        $app = require \Illuminate\Foundation\Application::inferBasePath().'/bootstrap/app.php';
        // Optional isolated runner: never load the local application's secrets.
        $app->useEnvironmentPath(__DIR__.'/Support/isolated-environment');
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }
}
