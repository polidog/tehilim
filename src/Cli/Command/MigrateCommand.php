<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Cli\Command;

use Polidog\Tehilim\Config;
use Polidog\Tehilim\Driver\Drivers;
use Polidog\Tehilim\Migration\MigrationStore;
use Polidog\Tehilim\Migration\Migrator;
use Polidog\Tehilim\Schema\Parser;
use RuntimeException;

final class MigrateCommand
{
    /** @param array<int,string> $args */
    public function run(array $args): int
    {
        $sub = $args[0] ?? 'help';
        $rest = array_slice($args, 1);

        return match ($sub) {
            'dev' => $this->dev($rest),
            'deploy' => $this->deploy($rest),
            'status' => $this->status($rest),
            'reset' => $this->reset($rest),
            'help', '-h', '--help' => $this->help(),
            default => $this->unknown($sub),
        };
    }

    /** @param array<int,string> $args */
    private function dev(array $args): int
    {
        $opts = Options::parse($args);
        $name = $opts['extra']['name'] ?? 'migration';
        $migrator = $this->migrator($opts['schema']);

        $result = $migrator->dev((string) $name);
        if ($result['skipped']) {
            echo "No changes detected. Nothing to migrate.\n";

            return 0;
        }
        echo "Created migration {$result['id']} ({$result['statements']} stmts)\n";
        echo "  {$result['path']}\n";
        echo "Applied.\n";

        return 0;
    }

    /** @param array<int,string> $args */
    private function deploy(array $args): int
    {
        $opts = Options::parse($args);
        $applied = $this->migrator($opts['schema'])->deploy();
        if ($applied === []) {
            echo "Nothing to apply.\n";

            return 0;
        }
        foreach ($applied as $id) {
            echo "Applied {$id}\n";
        }

        return 0;
    }

    /** @param array<int,string> $args */
    private function status(array $args): int
    {
        $opts = Options::parse($args);
        $rows = $this->migrator($opts['schema'])->status();
        if ($rows === []) {
            echo "No migrations.\n";

            return 0;
        }
        foreach ($rows as $r) {
            $mark = $r['applied'] ? '✓' : ' ';
            echo "  [{$mark}] {$r['id']}\n";
        }

        return 0;
    }

    /** @param array<int,string> $args */
    private function reset(array $args): int
    {
        $opts = Options::parse($args);
        if (!isset($opts['extra']['force'])) {
            fwrite(STDERR, "tehilim: 'migrate reset' drops every table and re-applies all migrations. Re-run with --force to confirm.\n");

            return 1;
        }
        $this->migrator($opts['schema'])->reset();
        echo "Database reset and migrations re-applied.\n";

        return 0;
    }

    private function migrator(string $schemaPath): Migrator
    {
        $schema = Parser::parseFile($schemaPath);
        $ds = $schema->datasources[0] ?? throw new RuntimeException('schema has no datasource block');
        $url = $ds->url() ?? throw new RuntimeException("datasource '{$ds->name}' has no url");
        $resolvedUrl = Options::resolveSqliteUrl($url, $schemaPath);
        $driver = Drivers::forPdo(Config::pdo($resolvedUrl));

        $migrationsDir = dirname(realpath($schemaPath) ?: $schemaPath) . '/migrations';
        $store = new MigrationStore($migrationsDir);

        return new Migrator($driver, $store, $schemaPath);
    }

    private function help(): int
    {
        echo <<<'TXT'
Usage:
  tehilim migrate dev     --name <slug> [--schema <path>]  Diff, write, and apply a new migration
  tehilim migrate deploy  [--schema <path>]                Apply unapplied migrations
  tehilim migrate status  [--schema <path>]                Show applied / pending
  tehilim migrate reset   --force [--schema <path>]        Drop everything and re-apply (DEV ONLY)

TXT;

        return 0;
    }

    private function unknown(string $sub): int
    {
        fwrite(STDERR, "tehilim migrate: unknown subcommand '{$sub}'. Try 'tehilim migrate help'.\n");

        return 1;
    }
}
