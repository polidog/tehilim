<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Client;

use Polidog\Tehilim\Driver\Driver;
use Polidog\Tehilim\Query\WhereCompiler;

/**
 * Runtime base for generated per-model clients. Generated subclasses provide
 * the model metadata via the abstract accessors and add PHPStan-friendly
 * array-shape PHPDocs on the public API.
 */
abstract class BaseModelClient
{
    private readonly WhereCompiler $whereCompiler;
    protected ?BaseClient $root = null;

    public function __construct(protected readonly Driver $driver)
    {
        $this->whereCompiler = new WhereCompiler();
    }

    public function bindRoot(BaseClient $root): void
    {
        $this->root = $root;
    }

    abstract protected function table(): string;

    abstract protected function primaryKey(): ?string;

    /** @return list<string> */
    abstract protected function columns(): array;

    /** @return array<string,string> */
    abstract protected function columnTypes(): array;

    /** @return array<string, Relation> */
    protected function relations(): array
    {
        return [];
    }

    /**
     * @param array{where: array<string,mixed>, include?: array<string,mixed>, select?: array<string,bool>} $args
     * @return array<string,mixed>|null
     */
    protected function doFindUnique(array $args): ?array
    {
        return $this->doFindFirst($args);
    }

    /**
     * @param array{where?: array<string,mixed>, orderBy?: array<string,string>|list<array<string,string>>, take?: int, skip?: int, include?: array<string,mixed>, select?: array<string,bool>} $args
     * @return array<string,mixed>|null
     */
    protected function doFindFirst(array $args): ?array
    {
        $args['take'] = 1;
        $rows = $this->doFindMany($args);
        return $rows[0] ?? null;
    }

    /**
     * @param array{where?: array<string,mixed>, orderBy?: array<string,string>|list<array<string,string>>, take?: int, skip?: int, include?: array<string,mixed>, select?: array<string,bool>} $args
     * @return list<array<string,mixed>>
     */
    protected function doFindMany(array $args = []): array
    {
        $cache = $this->root?->cache();
        $cacheKey = $cache !== null ? $this->cacheKey('findMany', $args) : null;
        if ($cache !== null && $cacheKey !== null && $cache->has($cacheKey)) {
            /** @var list<array<string,mixed>> $hit */
            $hit = $cache->get($cacheKey);
            return $hit;
        }

        $select = $this->resolveSelect($args['select'] ?? null);

        [$whereSql, $params] = $this->compileWhere($args['where'] ?? []);

        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s',
            $this->selectColumns($select),
            $this->driver->quoteIdent($this->table()),
            $whereSql,
        );

        $sql .= $this->orderByClause($args['orderBy'] ?? null);

        if (isset($args['take'])) {
            $sql .= ' LIMIT ' . (int) $args['take'];
        }
        if (isset($args['skip'])) {
            $sql .= ' OFFSET ' . (int) $args['skip'];
        }

        $stmt = $this->driver->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $row) {
            /** @var array<string,mixed> $row */
            $out[] = $this->castRow($row);
        }

        $include = $args['include'] ?? null;
        if (is_array($include) && $out !== []) {
            $out = $this->attachIncludes($out, $include);
        }

        if ($cache !== null && $cacheKey !== null) {
            $cache->set($cacheKey, $out);
        }

        return $out;
    }

    /**
     * @param array{data: array<string,mixed>} $args
     * @return array<string,mixed>
     */
    protected function doInsert(array $args): array
    {
        $this->root?->flushCache();
        $data = $args['data'];
        $types = $this->columnTypes();
        $bound = [];
        foreach ($data as $col => $val) {
            $type = $types[$col] ?? 'string';
            $bound[$col] = $this->driver->bind($type, $val);
        }

        $row = $this->driver->insertReturning(
            $this->table(),
            $this->primaryKey(),
            $bound,
            $this->columns(),
        );
        return $this->castRow($row);
    }

    /**
     * @param array{where: array<string,mixed>, data: array<string,mixed>} $args
     * @return array<string,mixed>
     */
    protected function doUpdate(array $args): array
    {
        $this->root?->flushCache();
        $where = $args['where'];
        $data = $args['data'];
        $types = $this->columnTypes();

        $setParts = [];
        $params = [];
        foreach ($data as $col => $val) {
            $type = $types[$col] ?? 'string';
            $setParts[] = $this->driver->quoteIdent($col) . ' = ?';
            $params[] = $this->driver->bind($type, $val);
        }

        [$whereSql, $whereParams] = $this->compileWhere($where);
        $params = [...$params, ...$whereParams];

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->driver->quoteIdent($this->table()),
            implode(', ', $setParts),
            $whereSql,
        );

        $stmt = $this->driver->pdo()->prepare($sql);
        $stmt->execute($params);

        $row = $this->doFindFirst(['where' => $where]);
        if ($row === null) {
            throw new \RuntimeException('Updated row not found');
        }
        return $row;
    }

    /**
     * @param array{where: array<string,mixed>} $args
     * @return array<string,mixed>
     */
    protected function doDelete(array $args): array
    {
        $this->root?->flushCache();
        $row = $this->doFindFirst(['where' => $args['where']]);
        if ($row === null) {
            throw new \RuntimeException('Row to delete not found');
        }

        [$whereSql, $params] = $this->compileWhere($args['where']);
        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $this->driver->quoteIdent($this->table()),
            $whereSql,
        );
        $stmt = $this->driver->pdo()->prepare($sql);
        $stmt->execute($params);

        return $row;
    }

    /**
     * @param array{where: array<string,mixed>, update: array<string,mixed>, insert: array<string,mixed>} $args
     * @return array<string,mixed>
     */
    protected function doUpsert(array $args): array
    {
        $this->root?->flushCache();
        $existing = $this->doFindFirst(['where' => $args['where']]);
        if ($existing !== null) {
            return $this->doUpdate(['where' => $args['where'], 'data' => $args['update']]);
        }
        return $this->doInsert(['data' => $args['insert']]);
    }

    /**
     * @param array{data: list<array<string,mixed>>, skipDuplicates?: bool} $args
     * @return array{count: int}
     */
    protected function doInsertMany(array $args): array
    {
        $this->root?->flushCache();
        $rows = $args['data'];
        if ($rows === []) {
            return ['count' => 0];
        }
        $skipDup = (bool) ($args['skipDuplicates'] ?? false);
        $types = $this->columnTypes();

        $columnSet = [];
        foreach ($rows as $r) {
            foreach (array_keys($r) as $k) {
                $columnSet[$k] = true;
            }
        }
        $columns = array_keys($columnSet);

        $params = [];
        foreach ($rows as $r) {
            foreach ($columns as $c) {
                $v = $r[$c] ?? null;
                $type = $types[$c] ?? 'string';
                $params[] = $v === null ? null : $this->driver->bind($type, $v);
            }
        }

        $sql = $this->driver->multiInsertSql($this->table(), $columns, count($rows), $skipDup);
        $stmt = $this->driver->pdo()->prepare($sql);
        $stmt->execute($params);

        return ['count' => $stmt->rowCount()];
    }

    /**
     * @param array{where?: array<string,mixed>, data: array<string,mixed>} $args
     * @return array{count: int}
     */
    protected function doUpdateMany(array $args): array
    {
        $this->root?->flushCache();
        $where = $args['where'] ?? [];
        $data = $args['data'];
        if ($data === []) {
            return ['count' => 0];
        }
        $types = $this->columnTypes();

        $setParts = [];
        $params = [];
        foreach ($data as $col => $val) {
            $type = $types[$col] ?? 'string';
            $setParts[] = $this->driver->quoteIdent($col) . ' = ?';
            $params[] = $this->driver->bind($type, $val);
        }

        [$whereSql, $whereParams] = $this->compileWhere($where);
        $params = [...$params, ...$whereParams];

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->driver->quoteIdent($this->table()),
            implode(', ', $setParts),
            $whereSql,
        );
        $stmt = $this->driver->pdo()->prepare($sql);
        $stmt->execute($params);
        return ['count' => $stmt->rowCount()];
    }

    /**
     * @param array{where?: array<string,mixed>} $args
     * @return array{count: int}
     */
    protected function doDeleteMany(array $args = []): array
    {
        $this->root?->flushCache();
        [$whereSql, $params] = $this->compileWhere($args['where'] ?? []);
        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $this->driver->quoteIdent($this->table()),
            $whereSql,
        );
        $stmt = $this->driver->pdo()->prepare($sql);
        $stmt->execute($params);
        return ['count' => $stmt->rowCount()];
    }

    /**
     * @param array{where?: array<string,mixed>} $args
     */
    protected function doCount(array $args = []): int
    {
        $cache = $this->root?->cache();
        $cacheKey = $cache !== null ? $this->cacheKey('count', $args) : null;
        if ($cache !== null && $cacheKey !== null && $cache->has($cacheKey)) {
            return (int) $cache->get($cacheKey);
        }

        [$whereSql, $params] = $this->compileWhere($args['where'] ?? []);
        $sql = sprintf(
            'SELECT COUNT(*) AS c FROM %s WHERE %s',
            $this->driver->quoteIdent($this->table()),
            $whereSql,
        );
        $stmt = $this->driver->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        $count = (int) ($row['c'] ?? 0);

        if ($cache !== null && $cacheKey !== null) {
            $cache->set($cacheKey, $count);
        }
        return $count;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed>       $include
     * @return list<array<string,mixed>>
     */
    private function attachIncludes(array $rows, array $include): array
    {
        $relations = $this->relations();
        foreach ($include as $name => $spec) {
            if ($spec === false) {
                continue;
            }
            $relation = $relations[$name]
                ?? throw new \InvalidArgumentException("Unknown relation '{$name}' on " . $this->table());

            /** @var array<string,mixed> $subArgs */
            $subArgs = $spec === true ? [] : (array) $spec;
            $rows = $this->loadRelation($rows, $name, $relation, $subArgs);
        }
        return $rows;
    }

    /**
     * @param list<array<string,mixed>>  $rows
     * @param array<string,mixed>        $subArgs
     * @return list<array<string,mixed>>
     */
    private function loadRelation(array $rows, string $name, Relation $relation, array $subArgs): array
    {
        if (count($relation->localFields) !== 1 || count($relation->foreignFields) !== 1) {
            throw new \LogicException("Composite-key relations are not supported in v1 (relation '{$name}')");
        }
        $localField = $relation->localFields[0];
        $foreignField = $relation->foreignFields[0];

        $ids = [];
        foreach ($rows as $row) {
            $v = $row[$localField] ?? null;
            if ($v !== null) {
                $ids[] = $v;
            }
        }
        $ids = array_values(array_unique($ids, SORT_REGULAR));

        if ($ids === []) {
            return $this->emptyRelationFill($rows, $name, $relation);
        }

        if ($this->root === null) {
            throw new \LogicException('include requires root client; was the model registered?');
        }

        $targetClient = $this->root->modelClient($relation->target);

        $where = $subArgs['where'] ?? [];
        $where[$foreignField] = ['in' => $ids];
        $subArgs['where'] = $where;

        $related = $targetClient->doFindMany($subArgs);

        $byKey = [];
        foreach ($related as $r) {
            $k = $r[$foreignField] ?? null;
            if ($k === null) {
                continue;
            }
            $byKey[(string) $k][] = $r;
        }

        foreach ($rows as $i => $row) {
            $k = $row[$localField] ?? null;
            $matches = $k === null ? [] : ($byKey[(string) $k] ?? []);
            $rows[$i][$name] = $relation->isList() ? $matches : ($matches[0] ?? null);
        }

        return $rows;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function emptyRelationFill(array $rows, string $name, Relation $relation): array
    {
        foreach ($rows as $i => $_) {
            $rows[$i][$name] = $relation->isList() ? [] : null;
        }
        return $rows;
    }

    /**
     * @param array<string,mixed> $where
     * @return array{0:string,1:list<mixed>}
     */
    private function compileWhere(array $where): array
    {
        return $this->whereCompiler->compile($where, $this->driver, $this->columnTypes());
    }

    /**
     * @param array<string,mixed> $args
     */
    private function cacheKey(string $op, array $args): string
    {
        $hash = $args |> serialize(...) |> md5(...);
        return "{$this->table()}:{$op}:{$hash}";
    }

    /**
     * @param array<string,bool>|null $select
     * @return list<string>
     */
    private function resolveSelect(?array $select): array
    {
        if ($select === null) {
            return $this->columns();
        }
        $picked = [];
        $valid = array_flip($this->columns());
        foreach ($select as $col => $on) {
            if (!$on) {
                continue;
            }
            if (!isset($valid[$col])) {
                continue;
            }
            $picked[] = $col;
        }
        if ($picked === []) {
            return $this->columns();
        }
        $pk = $this->primaryKey();
        if ($pk !== null && !in_array($pk, $picked, true)) {
            $picked[] = $pk;
        }
        return $picked;
    }

    /**
     * @param list<string> $columns
     */
    private function selectColumns(array $columns): string
    {
        if ($columns === []) {
            return '*';
        }
        return implode(', ', array_map($this->driver->quoteIdent(...), $columns));
    }

    /**
     * @param array<string,string>|list<array<string,string>>|null $orderBy
     */
    private function orderByClause(mixed $orderBy): string
    {
        if (!is_array($orderBy) || $orderBy === []) {
            return '';
        }
        $list = array_is_list($orderBy) ? $orderBy : [$orderBy];

        $parts = [];
        foreach ($list as $entry) {
            /** @var array<string,string> $entry */
            foreach ($entry as $col => $dir) {
                $dirUp = strtoupper((string) $dir);
                if (!in_array($dirUp, ['ASC', 'DESC'], true)) {
                    throw new \InvalidArgumentException("orderBy direction must be 'asc' or 'desc'");
                }
                $parts[] = $this->driver->quoteIdent($col) . ' ' . $dirUp;
            }
        }
        return $parts === [] ? '' : ' ORDER BY ' . implode(', ', $parts);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function castRow(array $row): array
    {
        $types = $this->columnTypes();
        $out = [];
        foreach ($row as $col => $val) {
            $type = $types[$col] ?? 'string';
            $out[$col] = $this->driver->cast($type, $val);
        }
        return $out;
    }
}
