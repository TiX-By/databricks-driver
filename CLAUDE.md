# CLAUDE.md — tixby/databricks-driver

## What this package is

A Laravel database driver that lets Eloquent and the Query Builder run against
**Databricks SQL warehouses** with no PDO and no native dependencies. It speaks the
**Databricks SQL Statement Execution REST API**
(https://docs.databricks.com/api/workspace/statementexecution) over the `Http` facade —
that choice is deliberate: pure PHP over HTTPS deploys anywhere (the alternative,
ODBC/Simba, requires native driver installs). Do not introduce PDO, ext-odbc, or
other native extensions.

- Private repo: `TiX-By/databricks-driver`, released by git tag (`v0.1.x`).
- Primary consumer: `TiX-By/intelligence-backend` (composer VCS repo, `^0.1`).
- Registered via auto-discovery: `DatabricksServiceProvider` → `DB::extend('databricks', ...)`.
  Consumers add a connection with `'driver' => 'databricks'` — see Config contract below.

## Architecture (src/)

| Class | Responsibility |
|---|---|
| `DatabricksConnection` | PDO-less `Illuminate\Database\Connection`. `$pdo` is a **throwing closure** — anything reaching `getPdo()` must fail loudly. All query paths run inside `$this->run()` so QueryException wrapping, query log, and `pretend()` keep working. |
| `DatabricksClient` | HTTP layer: execute → poll-until-terminal → fetch chunks (materializing `EXTERNAL_LINKS` presigned URLs transparently into `data_array`) → cancel. |
| `DatabricksQueryGrammar` | Spark SQL dialect. Extends the **base** Query Grammar, NOT MySqlGrammar (MySQL grammar drags in upserts/INSERT IGNORE/JSON ops that Spark can't run). Backtick wrapping handles 3-part `catalog.schema.table` names via `wrapSegments`. |
| `BindingConverter` | Laravel's positional `?` → named `:pN` API parameters. Single-pass scanner that skips `'…'`, `"…"`, `` `…` ``, `--` and `/* */` comments. Types inferred from **raw** PHP bindings: int→LONG, float→DOUBLE, bool→BOOLEAN, DateTimeInterface→TIMESTAMP, null→typed null, everything else→STRING. |
| `ResultHydrator` | JSON_ARRAY strings → typed stdClass rows using the manifest schema. |
| `DatabricksResult` | Readonly DTO for statement_id / columns / rowcount / truncated / chunks. |
| `Exceptions/` | `DatabricksException` base → Query / Timeout / ResultTruncated / ReadOnlyConnection. |

## Invariants — do not break these

1. **Read-only by default.** `read_only` config defaults to true; write paths throw
   `ReadOnlyConnectionException` BEFORE any HTTP leaves the machine, and `select()`
   defensively rejects write keywords (after stripping leading comments).
2. **Transactions throw.** Databricks has no multi-statement transactions. Never
   convert `transaction()/beginTransaction()/commit()/rollBack()` into silent no-ops —
   faking atomicity is worse than failing.
3. **DECIMAL stays a string.** Ticketing money must reconcile to the cent against the
   Vivenu dashboard; float coercion is a defect. Same for schema-side: hydrator casts
   INT/LONG/BIGINT→int, FLOAT/DOUBLE→float, BOOLEAN→bool, everything else stays string.
4. **Unsupported SQL fails loudly.** Locks, upserts, insertOrIgnore, insertGetId,
   JSON operators, full-text, multi-statement (`selectResultSets`), schema builder —
   all throw RuntimeException. Don't "add support" unless Spark SQL genuinely has the
   semantics.
5. **`Sleep::sleep()` only** — never `sleep()`/`usleep()`. Tests depend on `Sleep::fake()`.
6. **Retry discipline:** the initial POST retries only on `ConnectionException`
   (a statement may already be executing server-side); poll GETs treat 429/5xx as
   "still running" until the deadline; on deadline: best-effort cancel, then
   `DatabricksTimeoutException`.
7. **Chunks are fetched by computed index** (`/result/chunks/{n}`), not
   `next_chunk_internal_link`. `cursor()` must stay lazy — chunk N+1 is not fetched
   until the generator crosses chunk N. With `disposition=EXTERNAL_LINKS`, that
   laziness trades against link expiration (presigned URLs are short-lived,
   ~15min) — a slow `cursor()` consumer can hit an expired link on a later
   chunk; `select()` resolves every chunk up front and doesn't have this
   exposure.
8. **`affectingStatement()` returns 0** — the API has no reliable affected-rows
   contract. Documented behavior, not a bug.
9. **Compatibility floor: PHP ^8.2, illuminate ^11|^12.** No PHP 8.3+/8.4-only syntax.

## API contract notes

- `POST /api/2.0/sql/statements/` with `{warehouse_id, statement, catalog?, schema?,
  parameters[], wait_timeout: "50s", on_wait_timeout: CONTINUE, disposition: INLINE,
  format: JSON_ARRAY, row_limit?}`.
- `wait_timeout` is a STRING and only `"0s"` or `"5s"`–`"50s"` are valid — clamp, don't pass through.
- States: PENDING/RUNNING → poll; SUCCEEDED → hydrate; FAILED/CANCELED/CLOSED → throw.
- INLINE results cap at 25MiB; `manifest.truncated` + `strict_truncation` config decide
  throw-vs-warn. For larger results, set `disposition: 'EXTERNAL_LINKS'` — `DatabricksClient`
  fetches each chunk's presigned storage URL (`result.external_links` / chunk endpoint's
  `external_links`) and materializes it into the same `data_array` shape before it ever
  reaches `DatabricksResult`/`ResultHydrator`, so `DatabricksConnection`, `select()`,
  `cursor()`, and hydration are disposition-agnostic. The external-link fetch is a plain,
  unauthenticated request — the Databricks bearer token is never sent to the storage host;
  any `http_headers` the provider requires (e.g. Azure `x-ms-blob-type`) are forwarded as-is.
- Zero-row SUCCEEDED responses omit `result.data_array` — treat as `[]`, never an error.

## Config contract (consumer's config/database.php)

```php
'databricks' => [
    'driver' => 'databricks',
    'host' => env('DATABRICKS_HOST'),            // https://dbc-XXXX.cloud.databricks.com
    'token' => env('DATABRICKS_TOKEN'),          // PAT (OAuth M2M not yet supported)
    'warehouse_id' => env('DATABRICKS_WAREHOUSE_ID'),
    'catalog' => env('DATABRICKS_CATALOG'),      // optional; default namespace for raw SQL
    'schema' => env('DATABRICKS_SCHEMA'),        // optional
    'wait_timeout' => 50,
    'max_execution_seconds' => 300,
    'row_limit' => 100000,
    'strict_truncation' => true,
    'read_only' => true,
    'disposition' => env('DATABRICKS_DISPOSITION', 'INLINE'), // or 'EXTERNAL_LINKS'
],
```

Renaming or re-defaulting any key is a breaking change for consumers — bump minor/major
and note it in the tag message.

## Testing (non-negotiable)

- Pest 3 on Orchestra Testbench. Run: `./vendor/bin/pest` (or `composer test`).
- **Never call a real warehouse in tests.** `Http::fake()` + `Http::assertSent()` for
  every HTTP interaction; `Sleep::fake()` for polling. Response fixtures live in
  `tests/DatabricksFixtures.php` — extend it rather than inlining payloads.
- Every behavior change ships with a test. The suite currently covers: binding
  conversion (incl. quotes/comments edge cases), hydration/casting, grammar
  compilation + unsupported-feature throws, polling/backoff/timeout/cancel, chunked
  select + lazy cursor, read-only guard (incl. `Http::assertNothingSent()`), error
  mapping into QueryException, transaction refusal, EXTERNAL_LINKS chunk resolution
  (incl. asserting the bearer token never reaches the storage host, and provider
  `http_headers` forwarding — `tests/ExternalLinksTest.php`). Keep that coverage shape.

## Conventions & release flow

- `declare(strict_types=1)` everywhere; constructor property promotion; explicit return
  types; PHPDoc over inline comments (comment only what the code can't say).
- Conventional commits (`feat:` / `fix:` / `build:` / `chore:` / `test:`).
- Release: commit → `git tag v0.1.x` → `git push origin main v0.1.x` → consumers run
  `composer update tixby/databricks-driver`. The repo is private: consumers need a
  github-oauth composer credential.
- Consumer model convention (context, lives in the app not here): namespaces mirror
  Unity Catalog — `App\Models\Databricks\Vivenu\SilverMvp\DimEvent` with fully
  qualified `$table = 'vivenu.silver_mvp.dim_event'`.

## Live smoke check (optional, needs real credentials in env)

```php
DB::connection('databricks')->selectOne('select count(*) as c from id.bronze.users')->c;
```
Expect an int (~805k as of 2026-07-14, growing). Read-only guard check:
`->statement('CREATE TABLE t (i INT)')` must throw before any HTTP.
