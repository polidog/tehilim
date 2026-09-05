<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Client;

use Closure;
use InvalidArgumentException;
use LogicException;
use PDO;
use Polidog\Tehilim\Cache\RequestCache;
use Polidog\Tehilim\Driver\Driver;
use Polidog\Tehilim\Query\RawQuery;
use Polidog\Tehilim\Query\RawShape;
use Throwable;

abstract class BaseClient
{
    /** @var array<string, BaseModelClient> */
    private array $clients = [];

    private readonly RequestCache $cache;

    private readonly RawQuery $raw;

    /** @var null|(Closure(string, string, callable(): mixed): mixed) */
    private ?Closure $profiler = null;

    public function __construct(public readonly Driver $driver)
    {
        $this->cache = new RequestCache();
        $this->raw = new RawQuery($driver);
    }

    /**
     * Register a profiler hook. The callable is invoked around every
     * Tehilim operation with `($collector, $label, $fn)` and must return
     * whatever $fn returns. Matches Relayer's Profiler::measure() shape:
     *
     *   $db->withProfiler($relayer->profiler->measure(...));
     *
     * Pass null to clear.
     */
    public function withProfiler(?callable $profiler): static
    {
        $this->profiler = $profiler === null ? null : Closure::fromCallable($profiler);

        return $this;
    }

    /** @return null|(Closure(string, string, callable(): mixed): mixed) */
    public function profiler(): ?Closure
    {
        return $this->profiler;
    }

    /**
     * Request-scoped memoization store. Reads opt in per-call via
     * `$model->cached()->findX(...)`; any write through this client flushes
     * the entire store. The instance is created once per BaseClient and
     * lives until the client is discarded — typically one HTTP request.
     */
    public function cache(): RequestCache
    {
        return $this->cache;
    }

    public function flushCache(): void
    {
        $this->cache->flush();
    }

    /**
     * Run $fn inside a transaction (uses SAVEPOINT for nested calls).
     *
     * Throw {@see Rollback} from the callback to roll back silently; the
     * Rollback payload is returned. Any other Throwable rolls back and
     * propagates.
     *
     * `$isolation` sets the isolation level for the top-level transaction
     * (driver-dependent). It cannot be supplied on a nested call: SAVEPOINTs
     * inherit the outer transaction's isolation level.
     *
     * @template T
     *
     * @param callable(static): T $fn
     *
     * @return mixed|T
     */
    public function transaction(callable $fn, ?IsolationLevel $isolation = null): mixed
    {
        $pdo = $this->driver->pdo();

        if ($pdo->inTransaction()) {
            if ($isolation !== null) {
                throw new LogicException(
                    'Isolation level can only be set on a top-level transaction; nested calls reuse the outer transaction.',
                );
            }
            $sp = 'tehilim_sp_' . bin2hex(random_bytes(4));
            $pdo->prepare("SAVEPOINT {$sp}")->execute();

            try {
                $result = $fn($this);
                $pdo->prepare("RELEASE SAVEPOINT {$sp}")->execute();

                return $result;
            } catch (Rollback $r) {
                $this->safeSavepoint($pdo, "ROLLBACK TO SAVEPOINT {$sp}");
                $this->safeSavepoint($pdo, "RELEASE SAVEPOINT {$sp}");

                return $r->payload;
            } catch (Throwable $e) {
                $this->safeSavepoint($pdo, "ROLLBACK TO SAVEPOINT {$sp}");
                $this->safeSavepoint($pdo, "RELEASE SAVEPOINT {$sp}");

                throw $e;
            }
        }

        $this->driver->beginTransaction($isolation);

        try {
            $result = $fn($this);
            $pdo->commit();

            return $result;
        } catch (Rollback $r) {
            $this->safeRollback();

            return $r->payload;
        } catch (Throwable $e) {
            $this->safeRollback();

            throw $e;
        }
    }

    /**
     * Run a hand-written SELECT and return every row as an associative array.
     *
     * `$shape` maps result columns to Tehilim type tags — `int`, `BigInt`,
     * `float`, `bool`, `string`, `bytes`, `DateTime`, `json`, `mixed`, each
     * optionally prefixed with `?` for nullable (see {@see RawShape::TYPES}).
     * When a shape is given, every listed column is cast through the driver
     * (so `DateTime` comes back as DateTimeImmutable, `bool` as bool, …) and
     * the row is reduced to exactly those columns; a listed column missing
     * from the result set throws. The bundled PHPStan extension reads a
     * literal `$shape` and narrows the return type to `list<array{...}>`:
     *
     *     $rows = $db->queryRaw(
     *         'SELECT u.id, COUNT(p.id) AS posts FROM "User" u LEFT JOIN "Post" p ON p."authorId" = u.id GROUP BY u.id',
     *         [],
     *         ['id' => 'int', 'posts' => 'int'],
     *     );
     *     // PHPStan: list<array{id: int, posts: int}>
     *
     * Without a shape rows are returned exactly as PDO fetched them and the
     * static type stays `list<array<string,mixed>>`.
     *
     * Bind values are passed to PDO as-is except bool and DateTimeInterface,
     * which are normalized through the driver like generated-client writes.
     * Positional (`?`) and named (`:name`) placeholders both work.
     *
     * @param array<string,mixed>|list<mixed> $params
     * @param array<string,string>            $shape
     *
     * @return list<array<string,mixed>>
     */
    public function queryRaw(string $sql, array $params = [], array $shape = []): array
    {
        RawShape::validate($shape);

        return $this->profile('queryRaw', $sql, function () use ($sql, $params, $shape): array {
            $rows = $this->raw->fetchAll($sql, $params);
            if ($shape === []) {
                return $rows;
            }
            $out = [];
            foreach ($rows as $row) {
                $out[] = RawShape::castRow($this->driver, $shape, $row);
            }

            return $out;
        });
    }

    /**
     * Run a hand-written statement that does not return rows (INSERT /
     * UPDATE / DELETE / DDL) and return the affected row count. Flushes the
     * request cache first, like every other write through this client.
     *
     * @param array<string,mixed>|list<mixed> $params
     */
    public function executeRaw(string $sql, array $params = []): int
    {
        $this->flushCache();

        return $this->profile('executeRaw', $sql, fn (): int => $this->raw->execute($sql, $params));
    }

    public function modelClient(string $name): BaseModelClient
    {
        return $this->clients[$name]
            ?? throw new InvalidArgumentException("No client registered for model '{$name}'");
    }

    protected function registerModel(string $name, BaseModelClient $client): void
    {
        $this->clients[$name] = $client;
        $client->bindRoot($this);
    }

    /**
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    private function profile(string $op, string $label, callable $fn): mixed
    {
        if ($this->profiler === null) {
            return $fn();
        }

        return ($this->profiler)('tehilim.' . $op, $label, $fn);
    }

    private function safeRollback(): void
    {
        try {
            $this->driver->pdo()->rollBack();
        } catch (Throwable) {
            // no active transaction — swallow
        }
    }

    /**
     * Run a savepoint cleanup statement (ROLLBACK TO / RELEASE) without letting
     * its own failure mask the outcome being unwound. If the savepoint is
     * already gone or the connection was aborted, there is nothing left to do.
     */
    private function safeSavepoint(PDO $pdo, string $sql): void
    {
        try {
            $pdo->prepare($sql)->execute();
        } catch (Throwable) {
            // savepoint already released / connection aborted — swallow
        }
    }
}
