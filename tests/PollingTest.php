<?php

declare(strict_types=1);

use TixBy\Databricks\Exceptions\DatabricksTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use TixBy\Databricks\Tests\DatabricksFixtures;


beforeEach(function () {
    config()->set('database.connections.databricks', DatabricksFixtures::config());
    DB::purge('databricks');
});

it('polls a pending statement until it succeeds', function () {
    Sleep::fake();

    Http::fake([
        'dbx.test/*' => Http::sequence()
            ->push(DatabricksFixtures::pending())
            ->push(DatabricksFixtures::running())
            ->push(DatabricksFixtures::succeeded([['name' => 'c', 'type_name' => 'LONG']], [['7']])),
    ]);

    $rows = DB::connection('databricks')->select('select count(*) as c from users');

    expect($rows[0]->c)->toBe(7);

    Http::assertSentCount(3);
    Sleep::assertSleptTimes(2);
});

it('treats transient poll failures as still running', function () {
    Sleep::fake();

    Http::fake([
        'dbx.test/*' => Http::sequence()
            ->push(DatabricksFixtures::pending())
            ->push(['message' => 'busy'], 429)
            ->push(DatabricksFixtures::succeeded([['name' => 'c', 'type_name' => 'LONG']], [['1']])),
    ]);

    $rows = DB::connection('databricks')->select('select count(*) as c from users');

    expect($rows[0]->c)->toBe(1);
    Http::assertSentCount(3);
});

it('cancels and throws when the execution deadline is exceeded', function () {
    config()->set('database.connections.databricks', DatabricksFixtures::config(['max_execution_seconds' => 0]));
    DB::purge('databricks');
    Sleep::fake();

    Http::fake([
        'dbx.test/*' => Http::sequence()
            ->push(DatabricksFixtures::pending())
            ->push([], 200),
    ]);

    try {
        DB::connection('databricks')->select('select 1');
        $this->fail('Expected a QueryException.');
    } catch (QueryException $exception) {
        expect($exception->getPrevious())->toBeInstanceOf(DatabricksTimeoutException::class);
    }

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/cancel'));
});
