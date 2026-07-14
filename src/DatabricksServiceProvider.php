<?php

declare(strict_types=1);

namespace TixBy\Databricks;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

class DatabricksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->resolving('db', function (DatabaseManager $db) {
            $db->extend('databricks', function (array $config, string $name) {
                $config['name'] = $name;

                return new DatabricksConnection($config);
            });
        });
    }
}
