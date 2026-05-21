<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Client;

use Closure;
use InvalidArgumentException;
use Polidog\Tehilim\Cache\RequestCache;
use Polidog\Tehilim\Driver\Driver;
use Throwable;

abstract class BaseClient
{
    /** @var array<string, BaseModelClient> */
    private array $clients = [];

    private readonly RequestCache $cache;

    /** @var null|(Closure(string, string, callable(): mixed): mixed) */
    private ?Closure $profiler = null;

    public function __construct(public readonly Driver $driver)
    {
        $this->cache = new RequestCache();
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
     * @template T
     *
     * @param callable(static): T $fn
     *
     * @return mixed|T
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->driver->pdo();

        if ($pdo->inTransaction()) {
            $sp = 'tehilim_sp_' . bin2hex(random_bytes(4));
            $pdo->prepare("SAVEPOINT {$sp}")->execute();

            try {
                $result = $fn($this);
                $pdo->prepare("RELEASE SAVEPOINT {$sp}")->execute();

                return $result;
            } catch (Rollback $r) {
                $pdo->prepare("ROLLBACK TO SAVEPOINT {$sp}")->execute();
                $pdo->prepare("RELEASE SAVEPOINT {$sp}")->execute();

                return $r->payload;
            } catch (Throwable $e) {
                $pdo->prepare("ROLLBACK TO SAVEPOINT {$sp}")->execute();
                $pdo->prepare("RELEASE SAVEPOINT {$sp}")->execute();

                throw $e;
            }
        }

        $pdo->beginTransaction();

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

    private function safeRollback(): void
    {
        try {
            $this->driver->pdo()->rollBack();
        } catch (Throwable) {
            // no active transaction — swallow
        }
    }
}
