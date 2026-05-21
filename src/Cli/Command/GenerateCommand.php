<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Cli\Command;

use Polidog\Tehilim\Generator\Generator;
use Polidog\Tehilim\Schema\Parser;

final class GenerateCommand
{
    /** @param array<int,string> $args */
    public function run(array $args): int
    {
        $opts = Options::parse($args);
        $schema = Parser::parseFile($opts['schema']);

        $gen = $schema->generators[0] ?? null;
        if ($gen === null) {
            throw new \RuntimeException("schema has no 'generator' block");
        }

        $out = $gen->output() ?? './src/Generated';
        $ns = $gen->namespace();

        if (!str_starts_with($out, '/')) {
            $out = dirname(realpath($opts['schema']) ?: $opts['schema']) . '/' . ltrim($out, './');
        }

        (new Generator($schema, $out, $ns))->generate();

        echo "Generated client in {$out} (namespace {$ns})\n";
        return 0;
    }
}
