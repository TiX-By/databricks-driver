<?php

declare(strict_types=1);

namespace TixBy\Databricks;

use stdClass;

/**
 * Hydrates JSON_ARRAY result rows (all values arrive as strings) into
 * stdClass records typed according to the statement manifest schema.
 * DECIMAL deliberately stays a string for precision safety, matching
 * PDO MySQL behavior.
 */
final class ResultHydrator
{
    /**
     * @param list<list<string|null>> $rows
     * @param list<array{name: string, type_name?: string}> $columns
     * @return list<stdClass>
     */
    public function hydrate(array $rows, array $columns): array
    {
        $names = array_column($columns, 'name');
        $types = array_map(static fn (array $column): string => $column['type_name'] ?? 'STRING', $columns);

        $records = [];

        foreach ($rows as $row) {
            $record = new stdClass();

            foreach ($names as $position => $name) {
                $record->{$name} = $this->castValue($row[$position] ?? null, $types[$position]);
            }

            $records[] = $record;
        }

        return $records;
    }

    public function castValue(?string $raw, string $typeName): mixed
    {
        if ($raw === null) {
            return null;
        }

        $type = strtoupper((string) strtok($typeName, '('));

        return match ($type) {
            'TINYINT', 'SMALLINT', 'INT', 'INTEGER', 'LONG', 'BIGINT' => (int) $raw,
            'FLOAT', 'REAL', 'DOUBLE' => (float) $raw,
            'BOOLEAN' => $raw === 'true',
            default => $raw,
        };
    }
}
