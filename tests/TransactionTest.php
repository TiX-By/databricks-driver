<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use TixBy\Databricks\Tests\DatabricksFixtures;


beforeEach(function () {
    config()->set('database.connections.databricks', DatabricksFixtures::config());
    DB::purge('databricks');
});

it('refuses transaction callbacks', function () {
    DB::connection('databricks')->transaction(fn () => null);
})->throws(RuntimeException::class, 'transactions');

it('refuses manual transaction control', function () {
    DB::connection('databricks')->beginTransaction();
})->throws(RuntimeException::class, 'transactions');

it('reports a transaction level of zero', function () {
    expect(DB::connection('databricks')->transactionLevel())->toBe(0);
});

it('refuses schema builder access', function () {
    DB::connection('databricks')->getSchemaBuilder();
})->throws(RuntimeException::class, 'Schema operations');
