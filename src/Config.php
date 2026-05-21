<?php

declare(strict_types=1);

namespace Polidog\Tehilim;

use PDO;
use Polidog\Tehilim\Driver\Driver;
use Polidog\Tehilim\Driver\MySqlDriver;
use Polidog\Tehilim\Driver\PostgresDriver;
use Polidog\Tehilim\Driver\SqliteDriver;

final class Config
{
    public function __construct(
        public readonly string $provider,
        public readonly string $url,
    ) {}

    public static function fromUrl(string $url): self
    {
        $provider = match (true) {
            str_starts_with($url, 'sqlite:') => 'sqlite',
            str_starts_with($url, 'mysql:') => 'mysql',
            str_starts_with($url, 'pgsql:'), str_starts_with($url, 'postgres:'), str_starts_with($url, 'postgresql:') => 'postgresql',
            default => throw new \InvalidArgumentException("Cannot infer provider from URL: {$url}"),
        };
        return new self($provider, $url);
    }

    public function driver(): Driver
    {
        $pdo = $this->buildPdo();
        return match ($this->provider) {
            'sqlite' => new SqliteDriver($pdo),
            'mysql', 'mariadb' => new MySqlDriver($pdo),
            'postgresql', 'postgres' => new PostgresDriver($pdo),
            default => throw new \InvalidArgumentException("Unsupported provider: {$this->provider}"),
        };
    }

    private function buildPdo(): PDO
    {
        $url = $this->url;
        if (str_starts_with($url, 'sqlite:')) {
            return new PDO($url);
        }
        if (preg_match('#^(mysql|postgres|postgresql|pgsql)://(?:([^:@/]+)(?::([^@/]*))?@)?([^:/?]+)(?::(\d+))?/([^/?]+)#', $url, $m)) {
            $scheme = $m[1];
            $user = $m[2] !== '' ? $m[2] : null;
            $pass = $m[3] !== '' ? $m[3] : null;
            $host = $m[4];
            $port = $m[5] !== '' ? (int) $m[5] : null;
            $db = $m[6];

            $driverName = match ($scheme) {
                'mysql' => 'mysql',
                default => 'pgsql',
            };

            $dsn = "{$driverName}:host={$host};dbname={$db}";
            if ($port !== null) {
                $dsn .= ";port={$port}";
            }
            if ($driverName === 'mysql') {
                $dsn .= ';charset=utf8mb4';
            }
            return new PDO($dsn, $user, $pass);
        }
        if (str_starts_with($url, 'mysql:') || str_starts_with($url, 'pgsql:')) {
            return new PDO($url);
        }
        throw new \InvalidArgumentException("Cannot parse database URL: {$url}");
    }
}
