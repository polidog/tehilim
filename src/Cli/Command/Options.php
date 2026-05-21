<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Cli\Command;

final class Options
{
    /**
     * Accepts `--key value` and `--key=value`. Bare `--key` becomes `--key=1`.
     * `--schema` is hoisted out separately for convenience.
     *
     * @param array<int,string> $args
     * @return array{schema:string, extra:array<string,string>}
     */
    public static function parse(array $args, string $defaultSchema = 'schema.tehilim'): array
    {
        $schema = $defaultSchema;
        $extra = [];

        for ($i = 0, $n = count($args); $i < $n; $i++) {
            $a = $args[$i];
            if (!str_starts_with($a, '--')) {
                continue;
            }
            $key = substr($a, 2);
            if (str_contains($key, '=')) {
                [$key, $val] = explode('=', $key, 2);
            } elseif (isset($args[$i + 1]) && !str_starts_with($args[$i + 1], '--')) {
                $val = $args[++$i];
            } else {
                $val = '1';
            }
            if ($key === 'schema') {
                $schema = $val;
            } else {
                $extra[$key] = $val;
            }
        }

        return ['schema' => $schema, 'extra' => $extra];
    }
}
