<?php

declare(strict_types=1);

use TixBy\Databricks\Exceptions\DatabricksQueryException;
use TixBy\Databricks\Exceptions\DatabricksResultTruncatedException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use TixBy\Databricks\Tests\DatabricksFixtures;


beforeEach(function () {
    config()->set('database.connections.databricks', DatabricksFixtures::config());
    DB::purge('databricks');
});

it('wraps statement failures in a query exception carrying the sql', function () {
    Http::fake([
        'dbx.test/*' => Http::response(DatabricksFixtures::failed('TABLE_OR_VIEW_NOT_FOUND: missing_table', 'BAD_REQUEST')),
    ]);

    try {
        DB::connection('databricks')->select('select * from missing_table');
        $this->fail('Expected a QueryException.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('missing_table')
            ->and($exception->getPrevious())->toBeInstanceOf(DatabricksQueryException::class)
            ->and($exception->getPrevious()->errorCode)->toBe('BAD_REQUEST')
            ->and($exception->getPrevious()->statementId)->toBe('stmt-123');
    }
});

it('surfaces http-level errors from the statements endpoint', function () {
    Http::fake([
        'dbx.test/*' => Http::response(['message' => 'Invalid access token', 'error_code' => 'UNAUTHENTICATED'], 401),
    ]);

    expect(fn () => DB::connection('databricks')->select('select 1'))->toThrow(QueryException::class);
});

it('throws on truncated results when strict', function () {
    Http::fake([
        'dbx.test/*' => Http::response(DatabricksFixtures::succeeded(
            [['name' => 'id', 'type_name' => 'LONG']],
            [['1']],
            ['truncated' => true],
        )),
    ]);

    try {
        DB::connection('databricks')->select('select id from users');
        $this->fail('Expected a QueryException.');
    } catch (QueryException $exception) {
        expect($exception->getPrevious())->toBeInstanceOf(DatabricksResultTruncatedException::class);
    }
});

it('returns rows with a warning when strict truncation is disabled', function () {
    config()->set('database.connections.databricks', DatabricksFixtures::config(['strict_truncation' => false]));
    DB::purge('databricks');

    Http::fake([
        'dbx.test/*' => Http::response(DatabricksFixtures::succeeded(
            [['name' => 'id', 'type_name' => 'LONG']],
            [['1']],
            ['truncated' => true],
        )),
    ]);

    expect(DB::connection('databricks')->select('select id from users'))->toHaveCount(1);
});
