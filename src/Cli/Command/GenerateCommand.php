<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Cli\Command;

use Polidog\Tehilim\Generator\Generator;
use Polidog\Tehilim\Schema\Parser;
use RuntimeException;

final class GenerateCommand
{
    /** @param array<int,string> $args */
    public function run(array $args): int
    {
        $opts = Options::parse($args);
        $schema = Parser::parseFile($opts['schema']);

        $gen = $schema->generators[0] ?? null;
        if ($gen === null) {
            throw new RuntimeException("schema has no 'generator' block");
        }

        $out = $gen->output() ?? './src/Generated';
        $ns = $gen->namespace();

        if (!str_starts_with($out, '/')) {
            $base = dirname(realpath($opts['schema']) ?: $opts['schema']);
            $out = self::resolveRelativePath($base, $out);
        }

        (new Generator($schema, $out, $ns))->generate();

        echo "Generated client in {$out} (namespace {$ns})\n";

        return 0;
    }

    /**
     * Join $base with a relative $path, collapsing `.` and `..` segments.
     * `ltrim($path, './')` is wrong here because it eats the leading dots
     * of `..`, turning `../src` into `src` and breaking the new default
     * `generator.output = "../src/Generated"` layout.
     */
    private static function resolveRelativePath(string $base, string $path): string
    {
        $combined = rtrim($base, '/') . '/' . $path;
        $parts = [];
        foreach (explode('/', $combined) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $seg;
        }

        return '/' . implode('/', $parts);
    }
}
