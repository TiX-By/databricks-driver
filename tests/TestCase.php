<?php

declare(strict_types=1);

namespace TixBy\Databricks\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use TixBy\Databricks\DatabricksServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [DatabricksServiceProvider::class];
    }
}
