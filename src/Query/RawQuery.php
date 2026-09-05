<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Query;

use DateTimeInterface;
use PDO;
use Polidog\Tehilim\Driver\Driver;

/**
 * Thin PDO wrapper for hand-written SQL. Normalizes the few PHP values PDO
 * cannot bind sensibly on its own (bool, DateTimeInterface) through the
 * driver so raw queries bind them the same way the generated clients do.
 */
final class RawQuery
{
    public function __construct(private readonly Driver $driver) {}

    /**
     * @param array<string,mixed>|list<mixed> $params
     *
     * @return list<array<string,mixed>>
     */
    public function fetchAll(string $sql, array $params): array
    {
        $stmt = $this->driver->pdo()->prepare($sql);
        $stmt->execute($this->bindParams($params));

        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @param array<string,mixed>|list<mixed> $params
     *
     * @return int affected row count as reported by the driver
     */
    public function execute(string $sql, array $params): int
    {
        $stmt = $this->driver->pdo()->prepare($sql);
        $stmt->execute($this->bindParams($params));

        return $stmt->rowCount();
    }

    /**
     * @param array<string,mixed>|list<mixed> $params
     *
     * @return array<string,mixed>|list<mixed>
     */
    private function bindParams(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_bool($value)) {
                $params[$key] = $this->driver->bind('bool', $value);
            } elseif ($value instanceof DateTimeInterface) {
                $params[$key] = $this->driver->bind('DateTime', $value);
            }
        }

        return $params;
    }
}
