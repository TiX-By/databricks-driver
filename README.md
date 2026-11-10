# Laravel Databricks Driver

A Laravel database driver for **Databricks SQL warehouses**. It plugs Databricks into Eloquent and the Query Builder as a first-class connection — no PDO, no ODBC, no native extensions. All communication happens over the [Databricks SQL Statement Execution REST API](https://docs.databricks.com/api/workspace/statementexecution) using Laravel's `Http` client, so it deploys anywhere plain PHP over HTTPS works (containers, serverless, shared hosting, Vapor).

```php
$events = DB::connection('databricks')
    ->table('vivenu.silver_mvp.dim_event')
    ->where('status', 'active')
    ->orderByDesc('start_date')
    ->limit(20)
    ->get();
```

## Features

- **Zero native dependencies** — pure PHP over HTTPS. No Simba/ODBC driver installs, no `ext-odbc`, no PDO.
- **Full read support** — raw SQL, Query Builder, and Eloquent models against Unity Catalog tables, including 3-part `catalog.schema.table` names.
- **Parameterized queries** — Laravel's `?` bindings are converted to the API's typed named parameters (no string interpolation, safe against SQL injection).
- **Typed results** — rows are hydrated into `stdClass` objects with proper PHP types based on the warehouse's column manifest (`INT` → `int`, `DOUBLE` → `float`, `BOOLEAN` → `bool`).
- **Precision-safe money** — `DECIMAL` values are deliberately kept as strings so financial data never loses cents to float rounding.
- **Large result sets** — multi-chunk results are fetched transparently; `cursor()` streams chunks lazily so memory stays flat. Results over the 25 MiB inline cap can use `EXTERNAL_LINKS` disposition instead.
- **Read-only by default** — write statements are rejected *before any HTTP request leaves your machine* unless you explicitly opt in.
- **Resilient polling** — long-running statements are polled with backoff; 429/5xx responses are tolerated; statements that exceed your deadline are canceled server-side.
- **Honest failure modes** — unsupported operations (transactions, upserts, locks, schema builder) throw loud exceptions instead of silently doing the wrong thing.

## Requirements

| Requirement | Version |
|---|---|
| PHP | ^8.2 |
| Laravel (illuminate/database) | ^11.0 \|\| ^12.0 \|\| ^13.0 |
| Databricks | Any workspace with a [SQL warehouse](https://docs.databricks.com/en/compute/sql-warehouse/index.html) |

## Installation

### Step 1 — Require the package

If the package is available on Packagist:

```bash
composer require tixby/databricks-driver
```

Or install straight from GitHub by adding the repository to your application's `composer.json` first:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/TiX-By/databricks-driver" }
    ]
}
```

```bash
composer require tixby/databricks-driver:^0.1
```

The service provider is registered automatically via Laravel package discovery — there is nothing to add to `config/app.php`.

### Step 2 — Gather your Databricks credentials

You need three values from your Databricks workspace:

1. **Workspace host** — the base URL of your workspace, e.g. `https://dbc-a1b2c3d4-e5f6.cloud.databricks.com`. Copy it from your browser's address bar (no trailing slash needed; it is normalized either way).
2. **Personal access token (PAT)** — in Databricks go to **Settings → Developer → Access tokens → Generate new token**. Copy the token (it starts with `dapi...`). *(OAuth machine-to-machine auth is not yet supported.)*
3. **SQL warehouse ID** — go to **SQL Warehouses**, open your warehouse, and copy the **ID** from the **Connection details** tab (it also appears in the URL: `/sql/warehouses/<warehouse-id>`).

### Step 3 — Add the environment variables

In your application's `.env`:

```dotenv
DATABRICKS_HOST=https://dbc-a1b2c3d4-e5f6.cloud.databricks.com
DATABRICKS_TOKEN=dapiXXXXXXXXXXXXXXXXXXXXXXXXXXXX
DATABRICKS_WAREHOUSE_ID=1234567890abcdef
DATABRICKS_CATALOG=main
DATABRICKS_SCHEMA=default
```

### Step 4 — Register the connection

Add a `databricks` connection to the `connections` array in `config/database.php`:

```php
'connections' => [

    // ... your existing connections ...

    'databricks' => [
        'driver' => 'databricks',
        'host' => env('DATABRICKS_HOST'),
        'token' => env('DATABRICKS_TOKEN'),
        'warehouse_id' => env('DATABRICKS_WAREHOUSE_ID'),

        // Optional: default namespace applied to unqualified table names in raw SQL.
        'catalog' => env('DATABRICKS_CATALOG'),
        'schema' => env('DATABRICKS_SCHEMA'),

        // Optional tuning — the values below are the defaults.
        'wait_timeout' => 50,             // seconds the API holds the initial request open (0, or 5–50)
        'max_execution_seconds' => 300,   // total deadline before the statement is canceled
        'row_limit' => 100000,            // server-side cap on returned rows (0/null = no cap)
        'strict_truncation' => true,      // throw (true) or log a warning (false) when results are truncated
        'read_only' => true,              // reject INSERT/UPDATE/DELETE/DDL before any HTTP call
        'disposition' => env('DATABRICKS_DISPOSITION', 'INLINE'), // or 'EXTERNAL_LINKS' for results >25 MiB
    ],
],
```

`host`, `token`, and `warehouse_id` are required — the connection throws immediately if any is missing.

### Step 5 — Verify the connection

```bash
php artisan tinker
```

```php
DB::connection('databricks')->selectOne('SELECT current_catalog() AS catalog, current_timestamp() AS now');
// => {#... +"catalog": "main", +"now": "2026-07-14 12:34:56.789"}
```

If this returns a row, you are connected. Common failures at this step:

- `The databricks connection requires a 'host' config value.` — an env var is missing or the config cache is stale (`php artisan config:clear`).
- `Could not reach the Databricks workspace` — the host URL is wrong or unreachable from your network.
- A 403 message from the API — the token is invalid, expired, or lacks access to the warehouse.

## Usage

### Raw queries

```php
use Illuminate\Support\Facades\DB;

// Multiple rows
$rows = DB::connection('databricks')->select(
    'SELECT event_id, name, start_date FROM main.analytics.dim_event WHERE start_date >= ?',
    [now()->startOfYear()]
);

// Single row
$row = DB::connection('databricks')->selectOne(
    'SELECT count(*) AS total FROM main.analytics.fact_sales'
);
echo $row->total; // int

// Scalar
$total = DB::connection('databricks')->scalar(
    'SELECT sum(amount) FROM main.analytics.fact_sales WHERE sale_date = ?',
    ['2026-07-14']
);
```

### Query Builder

Everything read-oriented in the Query Builder works: wheres, joins, aggregates, grouping, ordering, limits, unions, subqueries.

```php
$db = DB::connection('databricks');

$topEvents = $db->table('main.analytics.fact_sales as s')
    ->join('main.analytics.dim_event as e', 'e.event_id', '=', 's.event_id')
    ->select('e.name', $db->raw('sum(s.amount) as revenue'), $db->raw('count(*) as tickets'))
    ->whereBetween('s.sale_date', ['2026-01-01', '2026-06-30'])
    ->groupBy('e.name')
    ->orderByDesc('revenue')
    ->limit(10)
    ->get();

$count = $db->table('main.analytics.dim_event')->where('status', 'active')->count();
```

Three-part Unity Catalog names are wrapped correctly with backticks: `main.analytics.dim_event` compiles to `` `main`.`analytics`.`dim_event` ``.

### Eloquent models

Point a model at the connection and give it a fully qualified table name. Analytics tables typically have no auto-incrementing key and no Laravel timestamps, so disable both:

```php
<?php

namespace App\Models\Databricks;

use Illuminate\Database\Eloquent\Model;

class DimEvent extends Model
{
    protected $connection = 'databricks';

    protected $table = 'main.analytics.dim_event';

    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;
}
```

Then query it like any other model:

```php
$event = DimEvent::find('evt_123');

$active = DimEvent::query()
    ->where('status', 'active')
    ->whereDate('start_date', '>=', now())
    ->orderBy('start_date')
    ->get();

$paged = DimEvent::where('venue', 'like', '%San Juan%')->paginate(25);
```

> **Tip:** mirror your Unity Catalog layout in your namespace structure (e.g. `App\Models\Databricks\Analytics\DimEvent`) so models stay discoverable as the catalog grows.

### Parameter binding and types

Positional `?` placeholders are converted to the API's named, typed parameters. Types are inferred from the PHP value you bind:

| PHP value | Databricks parameter type |
|---|---|
| `int` | `LONG` |
| `float` | `DOUBLE` |
| `bool` | `BOOLEAN` |
| `DateTimeInterface` (incl. Carbon) | `TIMESTAMP` |
| `BackedEnum` | its backing value's type |
| `null` | typed `NULL` |
| everything else | `STRING` |

```php
DB::connection('databricks')->select(
    'SELECT * FROM main.analytics.fact_sales WHERE amount > ? AND refunded = ? AND sale_ts >= ?',
    [100, false, now()->subDays(7)]
);
```

Named bindings work too — use `:name` placeholders in the SQL and pass an associative array:

```php
DB::connection('databricks')->select(
    'SELECT * FROM main.analytics.dim_event WHERE status = :status',
    ['status' => 'active']
);
```

`?` characters inside string literals, quoted identifiers, and SQL comments are left untouched by the converter.

### Result types

Rows come back as `stdClass` objects, cast according to the column types reported by the warehouse:

| Databricks column type | PHP type |
|---|---|
| `TINYINT`, `SMALLINT`, `INT`, `BIGINT` / `LONG` | `int` |
| `FLOAT`, `REAL`, `DOUBLE` | `float` |
| `BOOLEAN` | `bool` |
| `DECIMAL(p, s)` | **`string`** (by design — see below) |
| `DATE`, `TIMESTAMP`, `STRING`, everything else | `string` |
| `NULL` | `null` |

**Why `DECIMAL` stays a string:** casting decimals to float silently corrupts financial data (`19.99` becomes `19.989999...`). Keeping the string lets you feed it to `bcmath`, `brick/math`, or a money library and reconcile to the cent. This matches how `PDO` + MySQL behaves, so code migrated from a MySQL connection keeps working.

### Large result sets: `cursor()` and chunking

Results larger than one API chunk are handled for you. `select()`/`get()` fetch every chunk eagerly and return the complete array. For big exports, use `cursor()` — it yields row by row and only fetches chunk *N+1* after you have consumed chunk *N*, keeping memory flat:

```php
$rows = DB::connection('databricks')->cursor(
    'SELECT * FROM main.analytics.fact_sales WHERE sale_date >= ?',
    ['2026-01-01']
);

foreach ($rows as $row) {
    // one stdClass at a time; chunks are fetched lazily behind the scenes
}
```

Eloquent's lazy streaming works the same way:

```php
DimEvent::where('status', 'active')->cursor()->each(function (DimEvent $event) {
    // ...
});
```

### Row limits and truncation

Databricks returns inline results up to 25 MiB; combined with `row_limit`, a query can come back **truncated**. The driver never lets that pass silently:

- `strict_truncation => true` (default): throws `DatabricksResultTruncatedException`. Narrow the query, raise `row_limit` (or set it to `0` for no cap), or switch to `cursor()`-friendly slices.
- `strict_truncation => false`: logs a warning and returns the partial result set. Don't reach for this on data that has to reconcile exactly (e.g. against a financial source of truth) — it hides missing rows instead of surfacing them. Raise `row_limit` (see [Results larger than 25 MiB](#results-larger-than-25-mib) for pairing it with `disposition`) so nothing is silently dropped.

### Results larger than 25 MiB

If a query's result set exceeds the 25 MiB `INLINE` cap, Databricks rejects it outright with `Inline byte limit exceeded`. Set `'disposition' => 'EXTERNAL_LINKS'` on the connection to lift that cap — each chunk is fetched from a presigned storage URL instead of embedded in the API response:

```php
'databricks' => [
    // ...
    'disposition' => 'EXTERNAL_LINKS',
],
```

`select()` and `cursor()` behave identically either way — chunk resolution happens transparently inside the client. Two things to know:

- Presigned links are short-lived (~15 minutes). A `select()` resolves every chunk immediately, so it's unaffected. A `cursor()` that's consumed slowly (e.g. paused on slow per-row work) can hit an expired link on a later chunk and throw `DatabricksQueryException`; prefer `select()` for `EXTERNAL_LINKS` results you can't consume quickly.
- The external-link fetch never sends your Databricks token to the storage host — it's a plain unauthenticated request, forwarding only whatever `http_headers` the cloud provider requires.

`EXTERNAL_LINKS` only lifts the 25 MiB *byte* cap — it does nothing about `row_limit`, a separate cap on *row count* (see below). A query with more rows than `row_limit` still comes back truncated even under `EXTERNAL_LINKS`. For a report that must reconcile exactly against a source of truth (no partial data acceptable), raise or disable both together:

```php
'databricks' => [
    // ...
    'disposition' => env('DATABRICKS_DISPOSITION', 'EXTERNAL_LINKS'),
    'row_limit' => env('DATABRICKS_ROW_LIMIT', 0), // 0 = no server-side row cap
],
```

### Writes (opt-in)

The connection is **read-only by default**. Write attempts fail fast — before any HTTP request is sent:

```php
DB::connection('databricks')->statement('CREATE TABLE t (i INT)');
// TixBy\Databricks\Exceptions\ReadOnlyConnectionException
```

To allow writes, set `'read_only' => false` on the connection (or a second, separate connection entry so reads stay protected):

```php
'databricks_rw' => [
    'driver' => 'databricks',
    // ... same credentials ...
    'read_only' => false,
],
```

```php
DB::connection('databricks_rw')->insert(
    'INSERT INTO main.analytics.audit_log (event, created_at) VALUES (?, ?)',
    ['sync_completed', now()]
);
```

> **Note:** the Statement Execution API does not report affected-row counts, so `update()`/`delete()`/`affectingStatement()` always return `0` after a successful execution. This is a documented API limitation, not a failure.

### Timeouts, polling, and cancellation

Statement execution is asynchronous under the hood:

1. The driver submits the statement and asks the API to hold the connection open for up to `wait_timeout` seconds (valid values: `0`, or `5`–`50`; anything else is clamped).
2. If the statement is still running, the driver polls its status with a `1s → 2s → 3s → 5s` backoff (then every 5s). `429` and `5xx` poll responses are treated as "still running", not errors.
3. If total execution exceeds `max_execution_seconds`, the driver sends a best-effort cancel to the warehouse and throws `DatabricksTimeoutException`.

For dashboards, keep `max_execution_seconds` tight. For heavy batch jobs, raise it.

## Error handling

All exceptions extend `TixBy\Databricks\Exceptions\DatabricksException`:

| Exception | When |
|---|---|
| `DatabricksException` | Configuration errors, unreachable workspace |
| `DatabricksQueryException` | The API rejected or failed the statement (`FAILED` / `CANCELED` / `CLOSED`); carries the Databricks `error_code` and `statement_id` |
| `DatabricksTimeoutException` | Execution exceeded `max_execution_seconds` (statement canceled) |
| `DatabricksResultTruncatedException` | Result truncated while `strict_truncation` is on |
| `ReadOnlyConnectionException` | A write was attempted on a read-only connection |

Errors thrown *during* query execution are wrapped in Laravel's standard `Illuminate\Database\QueryException` (with the Databricks exception as `getPrevious()`), so existing error handling, query logging, and `DB::pretend()` all behave normally:

```php
use Illuminate\Database\QueryException;
use TixBy\Databricks\Exceptions\DatabricksTimeoutException;

try {
    $rows = DB::connection('databricks')->select($sql, $bindings);
} catch (QueryException $e) {
    if ($e->getPrevious() instanceof DatabricksTimeoutException) {
        // retry later / shrink the query window
    }

    throw $e;
}
```

## Limitations

These operations throw a `RuntimeException` — deliberately, because Databricks SQL has no equivalent semantics and silently faking them would be worse:

| Operation | Why |
|---|---|
| `transaction()` / `beginTransaction()` / `commit()` / `rollBack()` | Databricks has no multi-statement transactions. Pretending to have them would fake atomicity your data doesn't actually get. |
| `upsert()`, `insertOrIgnore()` | No native equivalents in the grammar; use explicit `MERGE INTO` via raw SQL on a writable connection. |
| `insertGetId()` | Databricks has no auto-incrementing IDs. |
| `lockForUpdate()` / `sharedLock()` | No row locks in a lakehouse. |
| `whereJsonContains()` and other JSON operators | Not mapped; use Spark SQL functions (`get_json_object`, `:` syntax) in raw expressions. |
| `whereFullText()` | No full-text index support. |
| `Schema::` builder / migrations | Out of scope; manage DDL in Databricks itself (or raw `statement()` calls on a writable connection). |
| `selectResultSets()` | Multi-statement queries are not supported by the API. |

Additional notes:

- **OAuth M2M authentication** is not yet supported — use a personal access token.
- Queries that legitimately return zero rows return `[]`, never an error.

## How it works

```
Eloquent / Query Builder
        │
DatabricksConnection      PDO-less Connection; read-only guard; truncation guard
        │
BindingConverter          ? placeholders → typed :pN named parameters
        │
DatabricksClient          POST /api/2.0/sql/statements → poll → fetch chunks → cancel
        │
ResultHydrator            JSON_ARRAY strings → typed stdClass rows (manifest schema)
```

The grammar extends Laravel's **base** query grammar (not MySQL's) with Spark SQL specifics — backtick identifier wrapping with 3-part name support, `rand()`, and hard failures for unsupported constructs — so the builder can never emit SQL that Databricks cannot execute.

## Testing

The suite runs on Pest + Orchestra Testbench and never touches a real warehouse — every HTTP interaction is faked and asserted:

```bash
composer test
```

If you contribute, please keep that property: `Http::fake()` for API interactions, `Sleep::fake()` for polling, fixtures in `tests/DatabricksFixtures.php`, and a test for every behavior change.

## License

MIT.
