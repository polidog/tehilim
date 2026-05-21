<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Cli;

use Polidog\Tehilim\Cli\Command\GenerateCommand;
use Polidog\Tehilim\Cli\Command\InitCommand;
use Polidog\Tehilim\Cli\Command\MigrateCommand;
use Polidog\Tehilim\Cli\Command\PushCommand;

final class Application
{
    /** @param array<int,string> $argv */
    public function run(array $argv): int
    {
        $cmd = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        try {
            return match ($cmd) {
                'init' => (new InitCommand())->run($args),
                'generate', 'gen' => (new GenerateCommand())->run($args),
                'push' => (new PushCommand())->run($args),
                'migrate' => (new MigrateCommand())->run($args),
                'help', '-h', '--help' => $this->help(),
                default => $this->unknown($cmd),
            };
        } catch (\Throwable $e) {
            fwrite(STDERR, "tehilim: " . $e->getMessage() . "\n");
            return 1;
        }
    }

    private function help(): int
    {
        echo <<<TXT
tehilim — schema-first PHP database toolkit

Usage:
  tehilim init [--schema <path>]            Create a starter schema.tehilim
  tehilim generate [--schema <path>]        Generate typed client from schema
  tehilim push [--schema <path>]            Sync schema to DB destructively (prototyping)
  tehilim migrate dev    --name <slug>      Diff schema, write a migration, apply it
  tehilim migrate deploy                    Apply unapplied migrations
  tehilim migrate status                    Show applied / pending migrations
  tehilim migrate reset                     Drop tables + re-apply all (DEV ONLY)

Default schema path: ./schema.tehilim

TXT;
        return 0;
    }

    private function unknown(string $cmd): int
    {
        fwrite(STDERR, "tehilim: unknown command '{$cmd}'. Run 'tehilim help'.\n");
        return 1;
    }
}
