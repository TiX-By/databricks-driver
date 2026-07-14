<?php

declare(strict_types=1);

use TixBy\Databricks\DatabricksConnection;
use TixBy\Databricks\Tests\DatabricksFixtures;

function databricksGrammarConnection(): DatabricksConnection
{
    return new DatabricksConnection(DatabricksFixtures::config(['name' => 'databricks']));
}

it('wraps identifiers in backticks and compiles limit and offset', function () {
    $sql = databricksGrammarConnection()
        ->table('users')
        ->select('id', 'email')
        ->where('age', '>', 21)
        ->limit(10)
        ->offset(5)
        ->toSql();

    expect($sql)->toBe('select `id`, `email` from `users` where `age` > ? limit 10 offset 5');
});

it('wraps three-part unity catalog table names segment by segment', function () {
    $sql = databricksGrammarConnection()->table('vivenu.silver_mvp.transactions')->toSql();

    expect($sql)->toBe('select * from `vivenu`.`silver_mvp`.`transactions`');
});

it('compiles random ordering to rand()', function () {
    $sql = databricksGrammarConnection()->table('users')->inRandomOrder()->toSql();

    expect($sql)->toBe('select * from `users` order by rand()');
});

it('rejects lock clauses', function () {
    databricksGrammarConnection()->table('users')->lockForUpdate()->toSql();
})->throws(RuntimeException::class, 'Lock clauses are not supported');

it('rejects full text where clauses', function () {
    databricksGrammarConnection()->table('users')->whereFullText('bio', 'databricks')->toSql();
})->throws(RuntimeException::class, 'Full-text search');

it('rejects upsert compilation', function () {
    $connection = databricksGrammarConnection();

    $connection->getQueryGrammar()->compileUpsert($connection->table('users'), [['id' => 1]], ['id'], ['email']);
})->throws(RuntimeException::class, 'Upserts are not supported');

it('rejects insert or ignore compilation', function () {
    $connection = databricksGrammarConnection();

    $connection->getQueryGrammar()->compileInsertOrIgnore($connection->table('users'), [['id' => 1]]);
})->throws(RuntimeException::class);

it('rejects insert get id compilation', function () {
    $connection = databricksGrammarConnection();

    $connection->getQueryGrammar()->compileInsertGetId($connection->table('users'), ['id' => 1], 'id');
})->throws(RuntimeException::class, 'auto-incrementing');
