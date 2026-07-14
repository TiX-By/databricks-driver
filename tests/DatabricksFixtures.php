<?php

declare(strict_types=1);

namespace TixBy\Databricks\Tests;

final class DatabricksFixtures
{
    /**
     * @return array<string, mixed> a minimal valid databricks connection config
     */
    public static function config(array $overrides = []): array
    {
        return array_merge([
            'driver' => 'databricks',
            'host' => 'https://dbx.test',
            'token' => 'fake-token',
            'warehouse_id' => 'wh-123',
            'catalog' => 'id',
            'schema' => 'bronze',
            'wait_timeout' => 50,
            'max_execution_seconds' => 300,
            'row_limit' => 100000,
            'strict_truncation' => true,
            'read_only' => true,
        ], $overrides);
    }

    /**
     * @param list<array{name: string, type_name: string}> $columns
     * @param list<list<string|null>> $rows
     * @return array<string, mixed>
     */
    public static function succeeded(array $columns, array $rows, array $manifestOverrides = []): array
    {
        return [
            'statement_id' => 'stmt-123',
            'status' => ['state' => 'SUCCEEDED'],
            'manifest' => array_merge([
                'format' => 'JSON_ARRAY',
                'schema' => ['column_count' => count($columns), 'columns' => $columns],
                'total_chunk_count' => 1,
                'total_row_count' => count($rows),
                'truncated' => false,
            ], $manifestOverrides),
            'result' => [
                'chunk_index' => 0,
                'row_offset' => 0,
                'row_count' => count($rows),
                'data_array' => $rows,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function pending(string $statementId = 'stmt-123'): array
    {
        return ['statement_id' => $statementId, 'status' => ['state' => 'PENDING']];
    }

    /**
     * @return array<string, mixed>
     */
    public static function running(string $statementId = 'stmt-123'): array
    {
        return ['statement_id' => $statementId, 'status' => ['state' => 'RUNNING']];
    }

    /**
     * @return array<string, mixed>
     */
    public static function failed(string $message, string $errorCode = 'BAD_REQUEST'): array
    {
        return [
            'statement_id' => 'stmt-123',
            'status' => [
                'state' => 'FAILED',
                'error' => ['message' => $message, 'error_code' => $errorCode],
            ],
        ];
    }
}
