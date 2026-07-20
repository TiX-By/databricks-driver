<?php

declare(strict_types=1);

namespace TixBy\Databricks;

use TixBy\Databricks\Exceptions\DatabricksException;
use TixBy\Databricks\Exceptions\DatabricksQueryException;
use TixBy\Databricks\Exceptions\DatabricksTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * HTTP layer over the Databricks SQL Statement Execution API.
 *
 * @see https://docs.databricks.com/api/workspace/statementexecution
 */
final class DatabricksClient
{
    private const STATEMENTS_PATH = '/api/2.0/sql/statements/';

    /** @var list<int> seconds between polls; the last entry repeats */
    private const POLL_BACKOFF = [1, 2, 3, 5];

    private const DISPOSITIONS = ['INLINE', 'EXTERNAL_LINKS'];

    public function __construct(
        private readonly string $host,
        private readonly string $token,
        private readonly string $warehouseId,
        private readonly ?string $catalog,
        private readonly ?string $schema,
        private readonly int $waitTimeout = 50,
        private readonly int $maxExecutionSeconds = 300,
        private readonly string $disposition = 'INLINE',
    ) {
    }

    public static function fromConnectionConfig(array $config): self
    {
        foreach (['host', 'token', 'warehouse_id'] as $required) {
            if (empty($config[$required])) {
                throw new DatabricksException("The databricks connection requires a '{$required}' config value.");
            }
        }

        $disposition = strtoupper((string) ($config['disposition'] ?? 'INLINE'));

        if (! in_array($disposition, self::DISPOSITIONS, true)) {
            throw new DatabricksException(
                "The databricks connection 'disposition' config must be one of: " . implode(', ', self::DISPOSITIONS)
            );
        }

        return new self(
            host: rtrim((string) $config['host'], '/'),
            token: (string) $config['token'],
            warehouseId: (string) $config['warehouse_id'],
            catalog: ($config['catalog'] ?? null) !== null && $config['catalog'] !== '' ? (string) $config['catalog'] : null,
            schema: ($config['schema'] ?? null) !== null && $config['schema'] !== '' ? (string) $config['schema'] : null,
            waitTimeout: self::clampWaitTimeout((int) ($config['wait_timeout'] ?? 50)),
            maxExecutionSeconds: max(0, (int) ($config['max_execution_seconds'] ?? 300)),
            disposition: $disposition,
        );
    }

    /**
     * Execute a statement and block until it reaches a terminal state.
     *
     * @param list<array{name: string, value: string|null, type?: string}> $parameters
     */
    public function execute(string $sql, array $parameters = [], ?int $rowLimit = null): DatabricksResult
    {
        $payload = array_filter([
            'warehouse_id' => $this->warehouseId,
            'statement' => $sql,
            'catalog' => $this->catalog,
            'schema' => $this->schema,
            'parameters' => $parameters !== [] ? $parameters : null,
            'wait_timeout' => $this->waitTimeout . 's',
            'on_wait_timeout' => 'CONTINUE',
            'disposition' => $this->disposition,
            'format' => 'JSON_ARRAY',
            'row_limit' => $rowLimit,
        ], static fn (mixed $value): bool => $value !== null);

        $startedAt = microtime(true);

        try {
            $response = $this->pending()->post(self::STATEMENTS_PATH, $payload);
        } catch (ConnectionException $exception) {
            throw new DatabricksException('Could not reach the Databricks workspace: ' . $exception->getMessage(), previous: $exception);
        }

        if ($response->failed()) {
            throw new DatabricksQueryException(
                (string) ($response->json('message') ?? $response->body()),
                errorCode: $response->json('error_code'),
            );
        }

        return $this->settle($response->json(), $startedAt);
    }

    /**
     * @return array<string, mixed> the chunk payload ({data_array, row_offset, row_count, ...})
     */
    public function fetchChunk(string $statementId, int $chunkIndex): array
    {
        $response = $this->get(self::STATEMENTS_PATH . $statementId . '/result/chunks/' . $chunkIndex);

        if ($response->failed()) {
            throw new DatabricksQueryException(
                (string) ($response->json('message') ?? $response->body()),
                errorCode: $response->json('error_code'),
                statementId: $statementId,
            );
        }

        return $this->materializeChunk($response->json(), $statementId);
    }

    public function cancel(string $statementId): void
    {
        try {
            $this->pending()->post(self::STATEMENTS_PATH . $statementId . '/cancel');
        } catch (ConnectionException) {
            // Cancellation is best-effort; the warehouse reaps abandoned statements.
        }
    }

    /**
     * Poll a PENDING/RUNNING statement until terminal, honoring the execution deadline.
     *
     * @param array<string, mixed> $response
     */
    private function settle(array $response, float $startedAt): DatabricksResult
    {
        $poll = 0;

        while (true) {
            $state = $response['status']['state'] ?? 'FAILED';

            if ($state === 'SUCCEEDED') {
                $response['result'] = $this->materializeChunk(
                    $response['result'] ?? [],
                    (string) ($response['statement_id'] ?? ''),
                );

                return DatabricksResult::fromResponse($response);
            }

            if (in_array($state, ['FAILED', 'CANCELED', 'CLOSED'], true)) {
                throw new DatabricksQueryException(
                    (string) ($response['status']['error']['message'] ?? "Databricks statement {$state}."),
                    errorCode: $response['status']['error']['error_code'] ?? null,
                    statementId: $response['statement_id'] ?? null,
                );
            }

            $statementId = (string) ($response['statement_id'] ?? '');

            if (microtime(true) - $startedAt > $this->maxExecutionSeconds) {
                $this->cancel($statementId);

                throw new DatabricksTimeoutException(
                    "Databricks statement {$statementId} exceeded {$this->maxExecutionSeconds}s and was canceled."
                );
            }

            Sleep::sleep(self::POLL_BACKOFF[min($poll, count(self::POLL_BACKOFF) - 1)]);
            $poll++;

            $response = $this->pollStatement($statementId, $startedAt);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function pollStatement(string $statementId, float $startedAt): array
    {
        try {
            $response = $this->get(self::STATEMENTS_PATH . $statementId);
        } catch (ConnectionException $exception) {
            if (microtime(true) - $startedAt > $this->maxExecutionSeconds) {
                throw new DatabricksException('Lost connection to Databricks while polling: ' . $exception->getMessage(), previous: $exception);
            }

            return ['statement_id' => $statementId, 'status' => ['state' => 'RUNNING']];
        }

        if ($response->status() === 429 || $response->serverError()) {
            return ['statement_id' => $statementId, 'status' => ['state' => 'RUNNING']];
        }

        if ($response->failed()) {
            throw new DatabricksQueryException(
                (string) ($response->json('message') ?? $response->body()),
                errorCode: $response->json('error_code'),
                statementId: $statementId,
            );
        }

        return $response->json();
    }

    /**
     * With disposition=EXTERNAL_LINKS, chunk payloads carry a presigned
     * storage URL under `external_links` instead of an inline `data_array`.
     * Resolve it here so callers (settle/fetchChunk) always see `data_array`,
     * regardless of disposition.
     *
     * @param array<string, mixed> $chunk
     * @return array<string, mixed>
     */
    private function materializeChunk(array $chunk, string $statementId): array
    {
        if (isset($chunk['data_array'])) {
            return $chunk;
        }

        $link = $chunk['external_links'][0] ?? null;

        if ($link === null) {
            $chunk['data_array'] = [];

            return $chunk;
        }

        $chunk['data_array'] = $this->fetchExternalLink($link, $statementId);

        return $chunk;
    }

    /**
     * Fetches a chunk's rows from its presigned external link. This is a
     * plain, unauthenticated request to cloud storage (S3/ADLS/GCS) — the
     * Databricks bearer token must never be sent to that host.
     *
     * @param array{external_link?: string, http_headers?: array<string, string>} $link
     * @return list<list<string|null>>
     */
    private function fetchExternalLink(array $link, string $statementId): array
    {
        $url = (string) ($link['external_link'] ?? '');

        if ($url === '') {
            return [];
        }

        try {
            $response = Http::withHeaders($link['http_headers'] ?? [])
                ->connectTimeout(10)
                ->timeout($this->waitTimeout + 15)
                ->get($url);
        } catch (ConnectionException $exception) {
            throw new DatabricksException(
                'Could not reach the Databricks external result link: ' . $exception->getMessage(),
                previous: $exception,
            );
        }

        if ($response->failed()) {
            throw new DatabricksQueryException(
                'Failed to download Databricks external result link (HTTP ' . $response->status() . '). '
                    . 'The presigned link may have expired — external links are short-lived, so a slow '
                    . 'cursor() consumer can outlast one; prefer select() or resume within the link\'s window.',
                statementId: $statementId,
            );
        }

        return $response->json() ?? [];
    }

    private function get(string $path): Response
    {
        $attempts = 0;

        while (true) {
            try {
                return $this->pending()->get($path);
            } catch (ConnectionException $exception) {
                if (++$attempts >= 3) {
                    throw $exception;
                }

                Sleep::sleep(1);
            }
        }
    }

    private function pending(): PendingRequest
    {
        return Http::baseUrl($this->host)
            ->withToken($this->token)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout($this->waitTimeout + 15);
    }

    private static function clampWaitTimeout(int $seconds): int
    {
        if ($seconds <= 0) {
            return 0;
        }

        return min(50, max(5, $seconds));
    }
}
