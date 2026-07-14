<?php

declare(strict_types=1);

use TixBy\Databricks\ResultHydrator;

it('hydrates rows into stdClass records typed by the manifest schema', function () {
    $columns = [
        ['name' => 'id', 'type_name' => 'LONG'],
        ['name' => 'email', 'type_name' => 'STRING'],
        ['name' => 'score', 'type_name' => 'DOUBLE'],
        ['name' => 'active', 'type_name' => 'BOOLEAN'],
        ['name' => 'balance', 'type_name' => 'DECIMAL(10,2)'],
        ['name' => 'created_at', 'type_name' => 'TIMESTAMP'],
    ];

    $rows = (new ResultHydrator())->hydrate([
        ['5', 'a@x.com', '9.5', 'true', '12.30', '2026-07-13T10:00:00Z'],
        ['6', null, '0.1', 'false', null, null],
    ], $columns);

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBeInstanceOf(stdClass::class)
        ->and($rows[0]->id)->toBeInt()->toBe(5)
        ->and($rows[0]->score)->toBeFloat()->toBe(9.5)
        ->and($rows[0]->active)->toBeTrue()
        ->and($rows[0]->balance)->toBeString()->toBe('12.30')
        ->and($rows[0]->created_at)->toBe('2026-07-13T10:00:00Z')
        ->and($rows[1]->email)->toBeNull()
        ->and($rows[1]->active)->toBeFalse()
        ->and($rows[1]->balance)->toBeNull();
});

it('casts scalar values by type name', function (?string $raw, string $type, mixed $expected) {
    expect((new ResultHydrator())->castValue($raw, $type))->toBe($expected);
})->with([
    'int' => ['42', 'INT', 42],
    'bigint' => ['9007199254740993', 'BIGINT', 9007199254740993],
    'float' => ['1.5', 'FLOAT', 1.5],
    'boolean true' => ['true', 'BOOLEAN', true],
    'boolean false' => ['false', 'BOOLEAN', false],
    'decimal stays string' => ['10.25', 'DECIMAL(18,2)', '10.25'],
    'string' => ['hola', 'STRING', 'hola'],
    'date stays string' => ['2026-07-13', 'DATE', '2026-07-13'],
    'null' => [null, 'LONG', null],
]);
