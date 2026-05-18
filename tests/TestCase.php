<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Radiergummi\OpenApi\OpenApiServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [OpenApiServiceProvider::class];
    }
}
