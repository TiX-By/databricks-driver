<?php

declare(strict_types=1);

use TixBy\Databricks\BindingConverter;

it('converts positional placeholders to named parameters', function () {
    $converted = (new BindingConverter())->convert('select * from users where id = ? and name = ?', [5, 'ana']);

    expect($converted['sql'])->toBe('select * from users where id = :p0 and name = :p1')
        ->and($converted['parameters'])->toBe([
            ['name' => 'p0', 'value' => '5', 'type' => 'LONG'],
            ['name' => 'p1', 'value' => 'ana'],
        ]);
});

it('ignores question marks inside string literals, identifiers, and comments', function () {
    $sql = "select '?', \"a?b\", `col?umn`, -- was it ?\n/* really? */ id from t where id = ?";

    $converted = (new BindingConverter())->convert($sql, [7]);

    expect($converted['sql'])->toBe("select '?', \"a?b\", `col?umn`, -- was it ?\n/* really? */ id from t where id = :p0")
        ->and($converted['parameters'])->toHaveCount(1);
});

it('handles escaped quotes inside string literals', function () {
    $sql = "select 'it''s ? fine', 'back\\' ?' from t where id = ?";

    $converted = (new BindingConverter())->convert($sql, [1]);

    expect($converted['sql'])->toEndWith('where id = :p0')
        ->and($converted['parameters'])->toHaveCount(1);
});

it('infers api types from raw php values', function () {
    $converted = (new BindingConverter())->convert(
        'select ?, ?, ?, ?, ?, ?',
        [42, 3.14, true, false, null, new DateTimeImmutable('2026-07-13 10:30:00')],
    );

    expect($converted['parameters'])->toBe([
        ['name' => 'p0', 'value' => '42', 'type' => 'LONG'],
        ['name' => 'p1', 'value' => '3.14', 'type' => 'DOUBLE'],
        ['name' => 'p2', 'value' => 'true', 'type' => 'BOOLEAN'],
        ['name' => 'p3', 'value' => 'false', 'type' => 'BOOLEAN'],
        ['name' => 'p4', 'value' => null],
        ['name' => 'p5', 'value' => '2026-07-13 10:30:00', 'type' => 'TIMESTAMP'],
    ]);
});

it('passes associative bindings through as named parameters', function () {
    $converted = (new BindingConverter())->convert('select * from t where id = :id', ['id' => 9]);

    expect($converted['sql'])->toBe('select * from t where id = :id')
        ->and($converted['parameters'])->toBe([['name' => 'id', 'value' => '9', 'type' => 'LONG']]);
});

it('throws when placeholders outnumber bindings', function () {
    (new BindingConverter())->convert('select * from t where a = ? and b = ?', [1]);
})->throws(InvalidArgumentException::class);

it('throws when bindings outnumber placeholders', function () {
    (new BindingConverter())->convert('select * from t where a = ?', [1, 2]);
})->throws(InvalidArgumentException::class);
