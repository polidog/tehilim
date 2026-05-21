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

    public function transaction(callable $fn): mixed
    {
        $pdo = $this->driver->pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($this);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
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
