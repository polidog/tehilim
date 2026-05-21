<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Cli\Command;

final class Options
{
    /**
     * @param array<int,string> $args
     * @return array{schema:string, extra:array<string,string>}
     */
    public static function parse(array $args, string $defaultSchema = 'schema.tehilim'): array
    {
        $schema = $defaultSchema;
        $extra = [];
        for ($i = 0, $n = count($args); $i < $n; $i++) {
            $a = $args[$i];
            if ($a === '--schema' && isset($args[$i + 1])) {
                $schema = $args[++$i];
                continue;
            }
            if (str_starts_with($a, '--') && str_contains($a, '=')) {
                [$k, $v] = explode('=', substr($a, 2), 2);
                $extra[$k] = $v;
            }
        }
        return ['schema' => $schema, 'extra' => $extra];
    }
}
