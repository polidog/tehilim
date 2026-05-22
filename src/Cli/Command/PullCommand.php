<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Cli\Command;

use Polidog\Tehilim\Config;
use Polidog\Tehilim\Driver\Drivers;
use Polidog\Tehilim\Schema\Ast\Schema;
use Polidog\Tehilim\Schema\Introspector;
use Polidog\Tehilim\Schema\Parser;
use Polidog\Tehilim\Schema\SchemaPrinter;
use RuntimeException;

/**
 * `tehilim pull` — introspect a live database and write the resulting schema.
 *
 * The datasource/generator blocks of an existing schema file are preserved;
 * only the models are regenerated from the database. Use `--print` to emit to
 * stdout instead of overwriting the file, and `--url` to override the
 * connection.
 */
final class PullCommand
{
    /** @param array<int,string> $args */
    public function run(array $args): int
    {
        $opts = Options::parse($args);
        $schemaPath = $opts['schema'];
        $print = isset($opts['extra']['print']);
        $urlOverride = $opts['extra']['url'] ?? null;

        $template = is_file($schemaPath) ? Parser::parseFile($schemaPath) : null;

        $url = $urlOverride ?? $this->urlFromTemplate($template);
        if ($url === null) {
            throw new RuntimeException(
                'No connection URL: pass --url, or run against a schema that has a datasource block.',
            );
        }

        $resolvedUrl = $this->resolveUrl($url, $schemaPath);
        $driver = Drivers::forPdo(Config::pdo($resolvedUrl));

        $schema = (new Introspector($driver))->introspect($template);
        $text = (new SchemaPrinter())->print($schema);

        if ($print) {
            echo $text;

            return 0;
        }

        $dir = dirname($schemaPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($schemaPath, $text);
        echo "Pulled schema into {$schemaPath}\n";

        return 0;
    }

    private function urlFromTemplate(?Schema $template): ?string
    {
        if ($template === null) {
            return null;
        }
        $ds = $template->datasources[0] ?? null;

        return $ds?->url();
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
