<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Client;

use Polidog\Tehilim\Driver\Driver;

abstract class BaseClient
{
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
}
