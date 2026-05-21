<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Cli\Command;

use Polidog\Tehilim\Config;
use Polidog\Tehilim\Driver\Drivers;
use Polidog\Tehilim\Migration\SchemaSync;
use Polidog\Tehilim\Schema\Parser;
use RuntimeException;

final class PushCommand
{
    /** @param array<int,string> $args */
    public function run(array $args): int
    {
        $opts = Options::parse($args);
        $schema = Parser::parseFile($opts['schema']);

        $ds = $schema->datasources[0] ?? null;
        if ($ds === null) {
            throw new RuntimeException("schema has no 'datasource' block");
        }

        $url = $ds->url() ?? throw new RuntimeException("datasource '{$ds->name}' has no 'url'");

        $resolvedUrl = $this->resolveUrl($url, $opts['schema']);
        $driver = Drivers::forPdo(Config::pdo($resolvedUrl));

        (new SchemaSync($driver, $schema))->push(drop: true);

        echo "Pushed schema to {$url}\n";

        return 0;
    }

    private function resolveUrl(string $url, string $schemaPath): string
    {
        if (!str_starts_with($url, 'sqlite:')) {
            return $url;
        }
        $path = substr($url, strlen('sqlite:'));
        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, ':')) {
            return $url;
        }
        $base = dirname(realpath($schemaPath) ?: $schemaPath);

        return 'sqlite:' . $base . '/' . ltrim($path, './');
    }
}
