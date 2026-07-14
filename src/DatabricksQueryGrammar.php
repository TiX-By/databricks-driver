<?php

declare(strict_types=1);

namespace TixBy\Databricks;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar;
use RuntimeException;

/**
 * Query grammar for the Databricks (Spark SQL) dialect. Extends the base
 * grammar rather than MySqlGrammar so MySQL-only constructs (upserts,
 * INSERT IGNORE, JSON operators) fail loudly instead of producing SQL
 * that Databricks cannot execute.
 */
class DatabricksQueryGrammar extends Grammar
{
    protected function wrapValue($value)
    {
        if ($value === '*') {
            return $value;
        }

        return '`' . str_replace('`', '``', $value) . '`';
    }

    public function compileRandom($seed)
    {
        return 'rand()';
    }

    protected function compileLock(Builder $query, $value)
    {
        throw new RuntimeException('Lock clauses are not supported by Databricks.');
    }

    public function compileUpsert(Builder $query, array $values, array $uniqueBy, array $update)
    {
        throw new RuntimeException('Upserts are not supported by the databricks driver.');
    }

    public function compileInsertOrIgnore(Builder $query, array $values)
    {
        throw new RuntimeException('INSERT OR IGNORE is not supported by the databricks driver.');
    }

    public function compileInsertOrIgnoreUsing(Builder $query, array $columns, string $sql)
    {
        throw new RuntimeException('INSERT OR IGNORE is not supported by the databricks driver.');
    }

    public function compileInsertGetId(Builder $query, $values, $sequence)
    {
        throw new RuntimeException('Databricks has no auto-incrementing ids; insertGetId is not supported.');
    }

    public function whereFullText(Builder $query, $where)
    {
        throw new RuntimeException('Full-text search clauses are not supported by Databricks.');
    }

    protected function compileJsonContains($column, $value)
    {
        throw new RuntimeException('JSON operators are not supported by the databricks driver.');
    }

    protected function compileJsonContainsKey($column)
    {
        throw new RuntimeException('JSON operators are not supported by the databricks driver.');
    }

    protected function compileJsonLength($column, $operator, $value)
    {
        throw new RuntimeException('JSON operators are not supported by the databricks driver.');
    }
}
