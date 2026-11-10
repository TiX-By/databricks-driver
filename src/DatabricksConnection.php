<?php

declare(strict_types=1);

namespace TixBy\Databricks;

use TixBy\Databricks\Exceptions\DatabricksResultTruncatedException;
use TixBy\Databricks\Exceptions\ReadOnlyConnectionException;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * PDO-less Laravel connection backed by the Databricks SQL Statement
 * Execution API. Read paths (select/cursor) are fully supported; write
 * paths are gated behind the connection's `read_only` config flag.
 * Databricks has no transactions — transactional methods throw rather
 * than silently faking atomicity.
 */
class DatabricksConnection extends Connection
{
    private const WRITE_KEYWORDS = [
        'INSERT', 'UPDATE', 'DELETE', 'MERGE', 'TRUNCATE', 'DROP',
        'ALTER', 'CREATE', 'REPLACE', 'GRANT', 'REVOKE', 'COPY',
    ];

    protected DatabricksClient $client;

    protected BindingConverter $bindingConverter;

    protected ResultHydrator $hydrator;

    public function __construct(array $config)
    {
        parent::__construct(
            static function (): never {
                throw new RuntimeException('The databricks driver does not use PDO.');
            },
            (string) ($config['catalog'] ?? ''),
            '',
            $config,
        );

        $this->client = DatabricksClient::fromConnectionConfig($config);
        $this->bindingConverter = new BindingConverter();
        $this->hydrator = new ResultHydrator();
    }

    protected function getDefaultQueryGrammar()
    {
        return new DatabricksQueryGrammar($this);
    }

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $this->ensureReadQuery($query);

        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return [];
            }

            $result = $this->executeStatement($query, $bindings);

            $this->guardTruncation($result, $query);

            $chunks = [$this->hydrator->hydrate($result->rows, $result->columns)];

            for ($chunk = 1; $chunk < $result->chunkCount; $chunk++) {
                $payload = $this->client->fetchChunk($result->statementId, $chunk);

                $chunks[] = $this->hydrator->hydrate($payload['data_array'] ?? [], $result->columns);
            }

            return array_merge(...$chunks);
        });
    }

    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $this->ensureReadQuery($query);

        $result = $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return null;
            }

            return $this->executeStatement($query, $bindings);
        });

        if (! $result instanceof DatabricksResult) {
            return;
        }

        $this->guardTruncation($result, $query);

        yield from $this->hydrator->hydrate($result->rows, $result->columns);

        for ($chunk = 1; $chunk < $result->chunkCount; $chunk++) {
            $payload = $this->client->fetchChunk($result->statementId, $chunk);

            yield from $this->hydrator->hydrate($payload['data_array'] ?? [], $result->columns);
        }
    }

    public function selectResultSets($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        throw new RuntimeException('Multi-statement queries are not supported by the databricks driver.');
    }

    public function statement($query, $bindings = [])
    {
        $this->ensureWritable($query);

        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return true;
            }

            $this->executeStatement($query, $bindings);
            $this->recordsHaveBeenModified();

            return true;
        });
    }

    /**
     * The Statement Execution API exposes no reliable affected-row count,
     * so this always reports 0 after a successful execution.
     */
    public function affectingStatement($query, $bindings = [])
    {
        $this->ensureWritable($query);

        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return 0;
            }

            $this->executeStatement($query, $bindings);
            $this->recordsHaveBeenModified();

            return 0;
        });
    }

    public function unprepared($query)
    {
        $this->ensureWritable($query);

        return $this->run($query, [], function ($query) {
            if ($this->pretending()) {
                return true;
            }

            $this->client->execute($query);
            $this->recordsHaveBeenModified();

            return true;
        });
    }

    public function transaction(Closure $callback, $attempts = 1)
    {
        throw new RuntimeException('Databricks does not support transactions.');
    }

    public function beginTransaction()
    {
        throw new RuntimeException('Databricks does not support transactions.');
    }

    public function commit()
    {
        throw new RuntimeException('Databricks does not support transactions.');
    }

    public function rollBack($toLevel = null)
    {
        throw new RuntimeException('Databricks does not support transactions.');
    }

    public function transactionLevel()
    {
        return 0;
    }

    public function getSchemaBuilder()
    {
        throw new RuntimeException('Schema operations are not supported on the databricks connection.');
    }

    public function getServerVersion(): string
    {
        return 'databricks';
    }

    protected function escapeString($value)
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }

    protected function escapeBool($value)
    {
        return $value ? 'true' : 'false';
    }

    protected function escapeBinary($value)
    {
        return "X'" . bin2hex($value) . "'";
    }

    public function getClient(): DatabricksClient
    {
        return $this->client;
    }

    protected function executeStatement(string $query, array $bindings): DatabricksResult
    {
        $converted = $this->bindingConverter->convert($query, $bindings, $this->getQueryGrammar()->getDateFormat());

        return $this->client->execute($converted['sql'], $converted['parameters'], $this->rowLimit());
    }

    protected function guardTruncation(DatabricksResult $result, string $query): void
    {
        if (! $result->truncated) {
            return;
        }

        if ($this->getConfig('strict_truncation') ?? true) {
            throw new DatabricksResultTruncatedException(sprintf(
                'Databricks truncated the result set (returned %d rows). Narrow the query, raise row_limit, or disable strict_truncation: %s',
                $result->totalRowCount,
                Str::limit(trim($query), 120),
            ));
        }

        Log::warning('Databricks result set was truncated.', [
            'connection' => $this->getName(),
            'statement_id' => $result->statementId,
            'returned_rows' => $result->totalRowCount,
        ]);
    }

    protected function rowLimit(): ?int
    {
        $limit = (int) ($this->getConfig('row_limit') ?? 0);

        return $limit > 0 ? $limit : null;
    }

    protected function isReadOnly(): bool
    {
        return (bool) ($this->getConfig('read_only') ?? true);
    }

    protected function ensureWritable(string $query): void
    {
        if ($this->isReadOnly()) {
            throw new ReadOnlyConnectionException(
                'The databricks connection is read-only; refusing to run: ' . Str::limit(trim($query), 120)
            );
        }
    }

    protected function ensureReadQuery(string $query): void
    {
        if (! $this->isReadOnly()) {
            return;
        }

        $keyword = strtoupper(preg_split('/\s+/', $this->stripLeadingComments($query), 2)[0] ?? '');

        if (in_array($keyword, self::WRITE_KEYWORDS, true)) {
            throw new ReadOnlyConnectionException(
                "The databricks connection is read-only; refusing to run {$keyword} statement."
            );
        }
    }

    protected function stripLeadingComments(string $query): string
    {
        $query = ltrim($query);

        while (true) {
            if (str_starts_with($query, '--')) {
                $newline = strpos($query, "\n");
                $query = $newline === false ? '' : ltrim(substr($query, $newline + 1));
            } elseif (str_starts_with($query, '/*')) {
                $close = strpos($query, '*/');
                $query = $close === false ? '' : ltrim(substr($query, $close + 2));
            } else {
                return $query;
            }
        }
    }
}
