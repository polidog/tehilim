<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Driver;

use PDO;

/**
 * Factory that picks the right Driver for a given PDO instance by inspecting
 * its PDO::ATTR_DRIVER_NAME. This lets callers bring their own PDO — already
 * configured with charset, timezone, persistent flag, statement cache, etc.
 */
final class Drivers
{
    public static function forPdo(PDO $pdo): Driver
    {
        $name = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        return match ($name) {
            'sqlite' => new SqliteDriver($pdo),
            'mysql'  => new MySqlDriver($pdo),
            'pgsql'  => new PostgresDriver($pdo),
            default  => throw new \InvalidArgumentException("Unsupported PDO driver: '{$name}'"),
        };
    }
}
