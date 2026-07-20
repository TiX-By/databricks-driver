<?php

declare(strict_types=1);

use TixBy\Databricks\Exceptions\DatabricksException;
use TixBy\Databricks\Exceptions\DatabricksQueryException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use TixBy\Databricks\Tests\DatabricksFixtures;

beforeEach(function () {
    config()->set('database.connections.databricks', DatabricksFixtures::config(['disposition' => 'EXTERNAL_LINKS']));
    DB::purge('databricks');
});

it('resolves the first chunk from a presigned external link', function () {
    Http::fake([
        'dbx.test/api/2.0/sql/statements/*' => Http::response(DatabricksFixtures::succeededExternalLink(
            [['name' => 'id', 'type_name' => 'LONG']],
            'https://storage.fake/chunk0',
            2,
        )),
        'storage.fake/chunk0' => Http::response([['1'], ['2']]),
    ]);

    $rows = DB::connection('databricks')->select('select id from users');

    expect(array_column($rows, 'id'))->toBe([1, 2]);

    Http::assertSent(function ($request) {
        return $request->url() !== 'https://storage.fake/chunk0' || ! $request->hasHeader('Authorization');
    });
});

it('resolves subsequent chunks from their own external links', function () {
    Http::fake([
        'dbx.test/api/2.0/sql/statements/stmt-123/result/chunks/*' => Http::response(
            DatabricksFixtures::chunkExternalLink(1, 'https://storage.fake/chunk1', 2)
        ),
        'dbx.test/api/2.0/sql/statements/*' => Http::response(DatabricksFixtures::succeededExternalLink(
            [['name' => 'id', 'type_name' => 'LONG']],
            'https://storage.fake/chunk0',
            2,
            ['total_chunk_count' => 2, 'total_row_count' => 4],
        )),
        'storage.fake/chunk0' => Http::response([['1'], ['2']]),
        'storage.fake/chunk1' => Http::response([['3'], ['4']]),
    ]);

    $rows = DB::connection('databricks')->select('select id from users');

    expect(array_column($rows, 'id'))->toBe([1, 2, 3, 4]);
});

it('never sends the databricks bearer token to the external storage host', function () {
    Http::fake([
        'dbx.test/api/2.0/sql/statements/*' => Http::response(DatabricksFixtures::succeededExternalLink(
            [['name' => 'id', 'type_name' => 'LONG']],
            'https://storage.fake/chunk0',
            1,
        )),
        'storage.fake/chunk0' => Http::response([['1']]),
    ]);

    DB::connection('databricks')->select('select id from users');

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://storage.fake/chunk0') {
            return true;
        }

        return ! $request->hasHeader('Authorization');
    });
});

it('forwards provider-required http_headers when fetching an external link', function () {
    Http::fake([
        'dbx.test/api/2.0/sql/statements/*' => Http::response([
            'statement_id' => 'stmt-123',
            'status' => ['state' => 'SUCCEEDED'],
            'manifest' => [
                'format' => 'JSON_ARRAY',
                'schema' => ['column_count' => 1, 'columns' => [['name' => 'id', 'type_name' => 'LONG']]],
                'total_chunk_count' => 1,
                'total_row_count' => 1,
                'truncated' => false,
            ],
            'result' => [
                'external_links' => [[
                    'chunk_index' => 0,
                    'row_offset' => 0,
                    'row_count' => 1,
                    'external_link' => 'https://storage.fake/chunk0',
                    'http_headers' => ['x-ms-blob-type' => 'BlockBlob'],
                ]],
            ],
        ]),
        'storage.fake/chunk0' => Http::response([['1']]),
    ]);

    DB::connection('databricks')->select('select id from users');

    Http::assertSent(function ($request) {
        return $request->url() !== 'https://storage.fake/chunk0'
            || $request->hasHeader('x-ms-blob-type', 'BlockBlob');
    });
});

it('throws a query exception when the external link download fails', function () {
    Http::fake([
        'dbx.test/api/2.0/sql/statements/*' => Http::response(DatabricksFixtures::succeededExternalLink(
            [['name' => 'id', 'type_name' => 'LONG']],
            'https://storage.fake/chunk0',
            1,
        )),
        'storage.fake/chunk0' => Http::response('Request has expired', 403),
    ]);

    try {
        DB::connection('databricks')->select('select id from users');
        test()->fail('Expected a QueryException.');
    } catch (QueryException $exception) {
        expect($exception->getPrevious())->toBeInstanceOf(DatabricksQueryException::class)
            ->and($exception->getPrevious()->getMessage())->toContain('expired');
    }
});

it('rejects an unsupported disposition config value', function () {
    config()->set('database.connections.databricks', DatabricksFixtures::config(['disposition' => 'ARROW_STREAM']));
    DB::purge('databricks');

    expect(fn () => DB::connection('databricks')->select('select 1'))
        ->toThrow(DatabricksException::class, "must be one of: INLINE, EXTERNAL_LINKS");
});
