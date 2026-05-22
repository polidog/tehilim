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
     *
     * @return array{schema:string, extra:array<string,string>}
     */
    public static function parse(array $args, string $defaultSchema = 'tehilim/schema.tehilim'): array
    {
        $schema = $defaultSchema;
        $extra = [];

        for ($i = 0, $n = count($args); $i < $n; ++$i) {
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

    /**
     * Resolve a relative SQLite file URL against the schema file's directory so
     * the CLI works regardless of the current working directory. Absolute
     * (`sqlite:/...`), special (`sqlite::memory:`), and non-SQLite URLs are
     * returned unchanged.
     */
    public static function resolveSqliteUrl(string $url, string $schemaPath): string
    {
        if (!str_starts_with($url, 'sqlite:')) {
            return $url;
        }
        $path = substr($url, strlen('sqlite:'));
        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, ':')) {
            return $url;
        }
        $base = dirname(realpath($schemaPath) ?: $schemaPath);
        // Strip only a single leading "./" — stripping all leading "./" chars
        // would corrupt "../foo" into "foo", losing the parent directory.
        $rel = str_starts_with($path, './') ? substr($path, 2) : $path;

        return 'sqlite:' . $base . '/' . $rel;
    }
}
