<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use TixBy\Databricks\Tests\DatabricksFixtures;

beforeEach(function () {
    config()->set('database.connections.databricks', DatabricksFixtures::config());
    DB::purge('databricks');
});

it('runs a bound select and hydrates typed rows', function () {
    Http::fake([
        'dbx.test/*' => Http::response(DatabricksFixtures::succeeded(
            [['name' => 'id', 'type_name' => 'LONG'], ['name' => 'email', 'type_name' => 'STRING']],
            [['1', 'a@x.com'], ['2', 'b@x.com']],
        )),
    ]);

    $rows = DB::connection('databricks')->select(
        'select id, email from users where id > ? and active = ?',
        [0, true],
    );

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBeInstanceOf(stdClass::class)
        ->and($rows[0]->id)->toBe(1)
        ->and($rows[0]->email)->toBe('a@x.com');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->method() === 'POST'
            && $request->url() === 'https://dbx.test/api/2.0/sql/statements/'
            && $body['warehouse_id'] === 'wh-123'
            && $body['statement'] === 'select id, email from users where id > :p0 and active = :p1'
            && $body['parameters'] === [
                ['name' => 'p0', 'value' => '0', 'type' => 'LONG'],
                ['name' => 'p1', 'value' => 'true', 'type' => 'BOOLEAN'],
            ]
            && $body['catalog'] === 'id'
            && $body['schema'] === 'bronze'
            && $body['format'] === 'JSON_ARRAY'
            && $body['disposition'] === 'INLINE'
            && $body['wait_timeout'] === '50s'
            && $body['row_limit'] === 100000;
    });
});

it('returns an empty array for zero-row results', function () {
    $response = DatabricksFixtures::succeeded([['name' => 'id', 'type_name' => 'LONG']], []);
    unset($response['result']['data_array']);

    Http::fake(['dbx.test/*' => Http::response($response)]);

    expect(DB::connection('databricks')->select('select id from users where 1 = 0'))->toBe([]);
});

it('builds queries through the query builder with three-part table names', function () {
    Http::fake([
        'dbx.test/*' => Http::response(DatabricksFixtures::succeeded(
            [['name' => 'aggregate', 'type_name' => 'LONG']],
            [['42']],
        )),
    ]);

    $total = DB::connection('databricks')->table('vivenu.silver_mvp.tickets_clean')->count();

    expect($total)->toBe(42);

    Http::assertSent(fn ($request) => str_replace('`aggregate`', 'aggregate', $request->data()['statement']) === 'select count(*) as aggregate from `vivenu`.`silver_mvp`.`tickets_clean`');
});
