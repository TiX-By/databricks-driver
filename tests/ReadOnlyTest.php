<?php

declare(strict_types=1);

use TixBy\Databricks\Exceptions\ReadOnlyConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use TixBy\Databricks\Tests\DatabricksFixtures;


beforeEach(function () {
    config()->set('database.connections.databricks', DatabricksFixtures::config());
    DB::purge('databricks');
});

it('rejects builder writes before any http call is made', function () {
    Http::fake();

    expect(fn () => DB::connection('databricks')->table('users')->insert(['id' => 1]))
        ->toThrow(ReadOnlyConnectionException::class)
        ->and(fn () => DB::connection('databricks')->table('users')->where('id', 1)->update(['email' => 'x']))
        ->toThrow(ReadOnlyConnectionException::class)
        ->and(fn () => DB::connection('databricks')->table('users')->where('id', 1)->delete())
        ->toThrow(ReadOnlyConnectionException::class)
        ->and(fn () => DB::connection('databricks')->statement('CREATE TABLE t (i INT)'))
        ->toThrow(ReadOnlyConnectionException::class)
        ->and(fn () => DB::connection('databricks')->unprepared('DROP TABLE t'))
        ->toThrow(ReadOnlyConnectionException::class);

    Http::assertNothingSent();
});

it('rejects write statements smuggled through select', function () {
    Http::fake();

    expect(fn () => DB::connection('databricks')->select('DELETE FROM users'))
        ->toThrow(ReadOnlyConnectionException::class)
        ->and(fn () => DB::connection('databricks')->select("-- sneaky\n/* very */ DROP TABLE users"))
        ->toThrow(ReadOnlyConnectionException::class);

    Http::assertNothingSent();
});

it('allows write statements when read_only is disabled', function () {
    config()->set('database.connections.databricks', DatabricksFixtures::config(['read_only' => false]));
    DB::purge('databricks');

    Http::fake(['dbx.test/*' => Http::response(DatabricksFixtures::succeeded([], []))]);

    expect(DB::connection('databricks')->statement('INSERT INTO scratch.tmp.t VALUES (1)'))->toBeTrue();

    Http::assertSentCount(1);
});
