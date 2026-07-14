<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use TixBy\Databricks\Tests\DatabricksFixtures;


beforeEach(function () {
    config()->set('database.connections.databricks', DatabricksFixtures::config());
    DB::purge('databricks');

    Http::fake([
        'dbx.test/api/2.0/sql/statements/stmt-123/result/chunks/*' => Http::response([
            'chunk_index' => 1,
            'data_array' => [['3'], ['4']],
        ]),
        'dbx.test/api/2.0/sql/statements/*' => Http::response(DatabricksFixtures::succeeded(
            [['name' => 'id', 'type_name' => 'LONG']],
            [['1'], ['2']],
            ['total_chunk_count' => 2, 'total_row_count' => 4],
        )),
    ]);
});

it('follows result chunks when selecting', function () {
    $rows = DB::connection('databricks')->select('select id from users');

    expect(array_column($rows, 'id'))->toBe([1, 2, 3, 4]);
    Http::assertSentCount(2);
});

it('cursors lazily, fetching the next chunk only when needed', function () {
    $generator = DB::connection('databricks')->cursor('select id from users');

    Http::assertSentCount(0);

    expect($generator->current()->id)->toBe(1);
    Http::assertSentCount(1);

    $generator->next();
    expect($generator->current()->id)->toBe(2);
    Http::assertSentCount(1);

    $generator->next();
    Http::assertSentCount(2);
    expect($generator->current()->id)->toBe(3);

    $generator->next();
    expect($generator->current()->id)->toBe(4);

    $generator->next();
    expect($generator->valid())->toBeFalse();
});
