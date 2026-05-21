<?php

declare(strict_types=1);

namespace Polidog\Tehilim;

use PDO;

/**
 * URL → PDO convenience. Accepts:
 *   - sqlite:./file.sqlite, sqlite::memory:, sqlite:/abs/path.sqlite
 *   - mysql://user:pass@host[:port]/db
 *   - postgres://user:pass@host[:port]/db, postgresql://..., pgsql://...
 *   - Native PDO DSNs (mysql:host=...;dbname=..., pgsql:host=...;dbname=...)
 *
 * Bring your own PDO with TehilimClient::fromPdo() if you need finer control
 * over attributes (charset, timezone, persistent connections, etc.).
 */
final class Config
{
    public static function pdo(string $url, ?string $user = null, ?string $password = null): PDO
    {
        if (str_starts_with($url, 'sqlite:')) {
            return new PDO($url, $user, $password);
        }
        if (str_starts_with($url, 'mysql:') || str_starts_with($url, 'pgsql:')) {
            return new PDO($url, $user, $password);
        }
        if (preg_match('#^(mysql|postgres|postgresql|pgsql)://(?:([^:@/]+)(?::([^@/]*))?@)?([^:/?]+)(?::(\d+))?/([^/?]+)#', $url, $m)) {
            $scheme = $m[1];
            $urlUser = $m[2] !== '' ? $m[2] : null;
            $urlPass = $m[3] !== '' ? $m[3] : null;
            $host = $m[4];
            $port = $m[5] !== '' ? (int) $m[5] : null;
            $db = $m[6];

            $driverName = $scheme === 'mysql' ? 'mysql' : 'pgsql';
            $dsn = "{$driverName}:host={$host};dbname={$db}";
            if ($port !== null) {
                $dsn .= ";port={$port}";
            }
            if ($driverName === 'mysql') {
                $dsn .= ';charset=utf8mb4';
            }
            return new PDO($dsn, $user ?? $urlUser, $password ?? $urlPass);
        }
        throw new \InvalidArgumentException("Cannot parse database URL: {$url}");
    }
}
