<?php

declare(strict_types=1);

namespace TixBy\Databricks;

final readonly class DatabricksResult
{
    /**
     * @param list<array{name: string, type_name: string, position?: int}> $columns
     * @param list<list<string|null>> $rows
     */
    public function __construct(
        public string $statementId,
        public array $columns,
        public int $totalRowCount,
        public bool $truncated,
        public int $chunkCount,
        public array $rows,
    ) {
    }

    /**
     * @param array<string, mixed> $response
     */
    public static function fromResponse(array $response): self
    {
        $manifest = $response['manifest'] ?? [];

        return new self(
            statementId: (string) ($response['statement_id'] ?? ''),
            columns: $manifest['schema']['columns'] ?? [],
            totalRowCount: (int) ($manifest['total_row_count'] ?? 0),
            truncated: (bool) ($manifest['truncated'] ?? false),
            chunkCount: (int) ($manifest['total_chunk_count'] ?? 1),
            rows: $response['result']['data_array'] ?? [],
        );
    }
}
