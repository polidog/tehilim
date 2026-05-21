<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Client;

use Polidog\Tehilim\Driver\Driver;

abstract class BaseClient
{
    /** @var array<string, BaseModelClient> */
    private array $clients = [];

    public function __construct(public readonly Driver $driver)
    {
    }

    /**
     * Run $fn inside a transaction (uses SAVEPOINT for nested calls).
     *
     * Throw {@see Rollback} from the callback to roll back silently; the
     * Rollback payload is returned. Any other Throwable rolls back and
     * propagates.
     *
     * @template T
     * @param callable(static): T $fn
     * @return T|mixed
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
            } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
            $this->safeRollback();
            throw $e;
        }
    }

    private function safeRollback(): void
    {
        try {
            $this->driver->pdo()->rollBack();
        } catch (\Throwable) {
            // no active transaction — swallow
        }
    }

    protected function registerModel(string $name, BaseModelClient $client): void
    {
        $this->clients[$name] = $client;
        $client->bindRoot($this);
    }

    public function modelClient(string $name): BaseModelClient
    {
        return $this->clients[$name]
            ?? throw new \InvalidArgumentException("No client registered for model '{$name}'");
    }
}
