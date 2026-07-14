<?php

declare(strict_types=1);

namespace TixBy\Databricks;

use BackedEnum;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Converts Laravel's positional `?` bindings into the named `:pN` parameters
 * required by the Databricks SQL Statement Execution API, inferring the API
 * parameter type from the raw PHP value.
 */
final class BindingConverter
{
    /**
     * @param array<int|string, mixed> $bindings
     * @return array{sql: string, parameters: list<array{name: string, value: string|null, type?: string}>}
     */
    public function convert(string $sql, array $bindings, string $dateFormat = 'Y-m-d H:i:s'): array
    {
        if ($bindings !== [] && ! array_is_list($bindings)) {
            $parameters = [];

            foreach ($bindings as $name => $value) {
                $parameters[] = $this->parameter((string) $name, $value, $dateFormat);
            }

            return ['sql' => $sql, 'parameters' => $parameters];
        }

        $converted = '';
        $parameters = [];
        $index = 0;
        $length = strlen($sql);
        $state = 'none';

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($state === 'none') {
                if ($char === "'" || $char === '"' || $char === '`') {
                    $state = $char;
                } elseif ($char === '-' && $next === '-') {
                    $state = 'line_comment';
                } elseif ($char === '/' && $next === '*') {
                    $state = 'block_comment';
                } elseif ($char === '?') {
                    if (! array_key_exists($index, $bindings)) {
                        throw new InvalidArgumentException(sprintf(
                            'Databricks query has more placeholders than bindings (%d given): %s', count($bindings), $sql
                        ));
                    }

                    $name = 'p' . $index;
                    $converted .= ':' . $name;
                    $parameters[] = $this->parameter($name, $bindings[$index], $dateFormat);
                    $index++;

                    continue;
                }
            } elseif ($state === "'" || $state === '"') {
                if ($char === '\\') {
                    $converted .= $char . $next;
                    $i++;

                    continue;
                }

                if ($char === $state) {
                    if ($next === $state) {
                        $converted .= $char . $next;
                        $i++;

                        continue;
                    }

                    $state = 'none';
                }
            } elseif ($state === '`') {
                if ($char === '`') {
                    if ($next === '`') {
                        $converted .= $char . $next;
                        $i++;

                        continue;
                    }

                    $state = 'none';
                }
            } elseif ($state === 'line_comment') {
                if ($char === "\n") {
                    $state = 'none';
                }
            } elseif ($state === 'block_comment') {
                if ($char === '*' && $next === '/') {
                    $converted .= $char . $next;
                    $i++;
                    $state = 'none';

                    continue;
                }
            }

            $converted .= $char;
        }

        if ($index !== count($bindings)) {
            throw new InvalidArgumentException(sprintf(
                'Databricks query has %d placeholders but %d bindings were given: %s', $index, count($bindings), $sql
            ));
        }

        return ['sql' => $converted, 'parameters' => $parameters];
    }

    /**
     * @return array{name: string, value: string|null, type?: string}
     */
    private function parameter(string $name, mixed $value, string $dateFormat): array
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return match (true) {
            $value === null => ['name' => $name, 'value' => null],
            is_bool($value) => ['name' => $name, 'value' => $value ? 'true' : 'false', 'type' => 'BOOLEAN'],
            is_int($value) => ['name' => $name, 'value' => (string) $value, 'type' => 'LONG'],
            is_float($value) => ['name' => $name, 'value' => (string) $value, 'type' => 'DOUBLE'],
            $value instanceof DateTimeInterface => ['name' => $name, 'value' => $value->format($dateFormat), 'type' => 'TIMESTAMP'],
            default => ['name' => $name, 'value' => (string) $value],
        };
    }
}
