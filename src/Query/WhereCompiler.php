<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Query;

use InvalidArgumentException;
use Polidog\Tehilim\Driver\Driver;

/**
 * Compiles Prisma-style where arrays into a SQL fragment + bound parameter list.
 *
 * Supported per-field operators:
 *   ['equals' => v], ['not' => v], ['in' => [...]], ['notIn' => [...]],
 *   ['lt'|'lte'|'gt'|'gte' => v], ['contains' => 'x'],
 *   ['startsWith' => 'x'], ['endsWith' => 'x']
 *
 * JSON path filters (when the field value carries a 'path' key):
 *   ['path' => ['a','b'], 'equals' => v], ['not' => v],
 *   ['string_contains'|'string_starts_with'|'string_ends_with' => 'x'],
 *   ['array_contains' => v]
 *
 * Top-level operators: AND, OR, NOT.
 *
 * Scalar field values are short-hand for ['equals' => value].
 */
final class WhereCompiler
{
    /**
     * @param array<string,mixed>  $where
     * @param array<string,string> $columnTypes field => php type
     *
     * @return array{0:string,1:list<mixed>}
     */
    public function compile(array $where, Driver $driver, array $columnTypes): array
    {
        $params = [];
        $sql = $this->compileGroup($where, $driver, $columnTypes, $params);

        return [$sql, $params];
    }

    /**
     * @param array<string,mixed>  $where
     * @param array<string,string> $columnTypes
     * @param list<mixed>          $params
     */
    private function compileGroup(array $where, Driver $driver, array $columnTypes, array &$params): string
    {
        if ($where === []) {
            return '1=1';
        }

        $parts = [];
        foreach ($where as $key => $value) {
            if ($key === 'AND' || $key === 'OR') {
                $sub = [];

                /** @var list<array<string,mixed>> $value */
                foreach ($value as $g) {
                    $sub[] = '(' . $this->compileGroup($g, $driver, $columnTypes, $params) . ')';
                }
                $parts[] = '(' . implode($key === 'AND' ? ' AND ' : ' OR ', $sub) . ')';

                continue;
            }
            if ($key === 'NOT') {
                /** @var array<string,mixed> $value */
                $parts[] = 'NOT (' . $this->compileGroup($value, $driver, $columnTypes, $params) . ')';

                continue;
            }

            $parts[] = $this->compileField((string) $key, $value, $driver, $columnTypes, $params);
        }

        return implode(' AND ', $parts);
    }

    /**
     * @param array<string,string> $columnTypes
     * @param list<mixed>          $params
     */
    private function compileField(string $field, mixed $value, Driver $driver, array $columnTypes, array &$params): string
    {
        $col = $driver->quoteIdent($field);
        $type = $columnTypes[$field] ?? 'string';

        if (!is_array($value)) {
            if ($value === null) {
                return "{$col} IS NULL";
            }
            $params[] = $driver->bind($type, $value);

            return "{$col} = ?";
        }

        if (array_key_exists('path', $value)) {
            return $this->compileJsonPath($col, $value, $driver, $params);
        }

        $clauses = [];
        foreach ($value as $op => $v) {
            switch ($op) {
                case 'equals':
                    if ($v === null) {
                        $clauses[] = "{$col} IS NULL";
                    } else {
                        $params[] = $driver->bind($type, $v);
                        $clauses[] = "{$col} = ?";
                    }

                    break;

                case 'not':
                    if ($v === null) {
                        $clauses[] = "{$col} IS NOT NULL";
                    } else {
                        $params[] = $driver->bind($type, $v);
                        $clauses[] = "{$col} <> ?";
                    }

                    break;

                case 'in':
                case 'notIn':
                    /** @var list<mixed> $list */
                    $list = $v;
                    if ($list === []) {
                        $clauses[] = $op === 'in' ? '1=0' : '1=1';

                        break;
                    }
                    $marks = implode(', ', array_fill(0, count($list), '?'));
                    foreach ($list as $item) {
                        $params[] = $driver->bind($type, $item);
                    }
                    $clauses[] = $col . ($op === 'in' ? ' IN ' : ' NOT IN ') . "({$marks})";

                    break;

                case 'lt': case 'lte': case 'gt': case 'gte':
                    $opSql = ['lt' => '<', 'lte' => '<=', 'gt' => '>', 'gte' => '>='][$op];
                    $params[] = $driver->bind($type, $v);
                    $clauses[] = "{$col} {$opSql} ?";

                    break;

                case 'contains':
                    $params[] = '%' . $this->escapeLike((string) $v) . '%';
                    $clauses[] = "{$col} LIKE ?";

                    break;

                case 'startsWith':
                    $params[] = $this->escapeLike((string) $v) . '%';
                    $clauses[] = "{$col} LIKE ?";

                    break;

                case 'endsWith':
                    $params[] = '%' . $this->escapeLike((string) $v);
                    $clauses[] = "{$col} LIKE ?";

                    break;

                default:
                    throw new InvalidArgumentException("Unknown where operator '{$op}' on field '{$field}'");
            }
        }

        return '(' . implode(' AND ', $clauses) . ')';
    }

    /**
     * Compile a JSON path filter. $col is already quoted. The value carries a
     * 'path' (list of keys) plus one or more operators applied to the value at
     * that path. Comparisons run against the extracted value as text.
     *
     * @param array<string,mixed> $value
     * @param list<mixed>         $params
     */
    private function compileJsonPath(string $col, array $value, Driver $driver, array &$params): string
    {
        $rawPath = $value['path'];
        if (!is_array($rawPath) || !array_is_list($rawPath)) {
            throw new InvalidArgumentException("JSON 'path' must be a list of keys");
        }
        $path = array_map(strval(...), $rawPath);

        $clauses = [];
        $textExpr = null;
        foreach ($value as $op => $v) {
            if ($op === 'path') {
                continue;
            }

            switch ($op) {
                case 'equals':
                    if ($v === null) {
                        $clauses[] = ($textExpr ??= $driver->jsonExtractText($col, $path)) . ' IS NULL';
                    } else {
                        $params[] = $driver->jsonComparisonText($v);
                        $clauses[] = ($textExpr ??= $driver->jsonExtractText($col, $path)) . ' = ?';
                    }

                    break;

                case 'not':
                    if ($v === null) {
                        $clauses[] = ($textExpr ??= $driver->jsonExtractText($col, $path)) . ' IS NOT NULL';
                    } else {
                        $params[] = $driver->jsonComparisonText($v);
                        $clauses[] = ($textExpr ??= $driver->jsonExtractText($col, $path)) . ' <> ?';
                    }

                    break;

                case 'string_contains':
                    $params[] = '%' . $this->escapeLike((string) $v) . '%';
                    $clauses[] = ($textExpr ??= $driver->jsonExtractText($col, $path)) . ' LIKE ?';

                    break;

                case 'string_starts_with':
                    $params[] = $this->escapeLike((string) $v) . '%';
                    $clauses[] = ($textExpr ??= $driver->jsonExtractText($col, $path)) . ' LIKE ?';

                    break;

                case 'string_ends_with':
                    $params[] = '%' . $this->escapeLike((string) $v);
                    $clauses[] = ($textExpr ??= $driver->jsonExtractText($col, $path)) . ' LIKE ?';

                    break;

                case 'array_contains':
                    [$sql, $bind] = $driver->jsonContains($col, $path, $v);
                    $params[] = $bind;
                    $clauses[] = $sql;

                    break;

                default:
                    throw new InvalidArgumentException("Unknown JSON path operator '{$op}'");
            }
        }

        if ($clauses === []) {
            throw new InvalidArgumentException("JSON 'path' filter requires at least one operator");
        }

        return '(' . implode(' AND ', $clauses) . ')';
    }

    private function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $s);
    }
}
