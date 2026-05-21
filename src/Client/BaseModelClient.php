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
    /**
     * Single-column primary key name, declared by generated subclasses so the
     * PHPStan extension can narrow find* return types when `select` is used.
     * Composite primary keys leave this null and skip PK auto-inclusion.
     */
    public const ?string PK = null;

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

    /**
     * Wrap $fn with the root client's profiler (Relayer-compatible
     * Profiler::measure shape). Zero overhead when no profiler is set.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function profile(string $op, callable $fn): mixed
    {
        $profiler = $this->root?->profiler();
        if ($profiler === null) {
            return $fn();
        }
        return $profiler('tehilim.' . $op, $this->table(), $fn);
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
     * @param array{where: array<string,mixed>, include?: array<string,mixed>, select?: array<string,bool>|list<string>} $args
     * @return array<string,mixed>|null
     */
    protected function doFindUnique(array $args): ?array
    {
        return $this->findOneCached('findUnique', $args);
    }

    /**
     * @param array{where?: array<string,mixed>, orderBy?: array<string,string>|list<array<string,string>>, take?: int, skip?: int, include?: array<string,mixed>, select?: array<string,bool>|list<string>} $args
     * @return array<string,mixed>|null
     */
    protected function doFindFirst(array $args): ?array
    {
        return $this->findOneCached('findFirst', $args);
    }

    /**
     * Shared core for doFindUnique / doFindFirst: cache lookup, then a
     * single profile frame for $op, then take=1 + execFindMany. Avoids the
     * nested findUnique → findFirst → findMany frame stack the previous
     * implementation produced.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>|null
     */
    private function findOneCached(string $op, array $args): ?array
    {
        $cache = $this->root?->cache();
        $cacheKey = $cache !== null ? $this->cacheKey($op, $args) : null;
        if ($cache !== null && $cacheKey !== null && $cache->has($cacheKey)) {
            /** @var array<string,mixed>|null $hit */
            $hit = $cache->get($cacheKey);
            return $hit;
        }

        return $this->profile($op, function () use ($args, $cache, $cacheKey): ?array {
            $args['take'] = 1;
            $rows = $this->execFindMany($args);
            $row = $rows[0] ?? null;
            if ($cache !== null && $cacheKey !== null) {
                $cache->set($cacheKey, $row);
            }
            return $row;
        });
    }

    /**
     * @param array{where?: array<string,mixed>, orderBy?: array<string,string>|list<array<string,string>>, take?: int, skip?: int, include?: array<string,mixed>, select?: array<string,bool>|list<string>} $args
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

        return $this->profile('findMany', function () use ($args, $cache, $cacheKey): array {
            $out = $this->execFindMany($args);
            if ($cache !== null && $cacheKey !== null) {
                $cache->set($cacheKey, $out);
            }
            return $out;
        });
    }

    /**
     * @param array{where?: array<string,mixed>, orderBy?: array<string,string>|list<array<string,string>>, take?: int, skip?: int, include?: array<string,mixed>, select?: array<string,bool>|list<string>} $args
     * @return list<array<string,mixed>>
     */
    private function execFindMany(array $args): array
    {
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

        return $out;
    }

    /**
     * @param array{data: array<string,mixed>} $args
     * @return array<string,mixed>
     */
    protected function doInsert(array $args): array
    {
        $this->root?->flushCache();
        return $this->profile('insert', function () use ($args): array {
            [$scalars, $relMutations] = $this->partitionRelationData($args['data']);
            $types = $this->columnTypes();
            $bound = [];
            foreach ($scalars as $col => $val) {
                $type = $types[$col] ?? 'string';
                $bound[$col] = $this->driver->bind($type, $val);
            }

            $row = $this->driver->insertReturning(
                $this->table(),
                $this->primaryKey(),
                $bound,
                $this->columns(),
            );
            $row = $this->castRow($row);

            if ($relMutations !== []) {
                $this->applyRelationMutations($relMutations, $row, mode: 'insert');
            }
            return $row;
        });
    }

    /**
     * @param array{where: array<string,mixed>, data: array<string,mixed>} $args
     * @return array<string,mixed>
     */
    protected function doUpdate(array $args): array
    {
        $this->root?->flushCache();
        return $this->profile('update', function () use ($args): array {
            $where = $args['where'];
            [$scalars, $relMutations] = $this->partitionRelationData($args['data']);
            $types = $this->columnTypes();

            if ($scalars !== []) {
                $setParts = [];
                $params = [];
                foreach ($scalars as $col => $val) {
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
            }

            $row = $this->doFindFirst(['where' => $where]);
            if ($row === null) {
                throw new \RuntimeException('Updated row not found');
            }

            if ($relMutations !== []) {
                $this->applyRelationMutations($relMutations, $row, mode: 'update');
            }
            return $row;
        });
    }

    /**
     * @param array{where: array<string,mixed>} $args
     * @return array<string,mixed>
     */
    protected function doDelete(array $args): array
    {
        $this->root?->flushCache();
        return $this->profile('delete', function () use ($args): array {
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
        });
    }

    /**
     * @param array{where: array<string,mixed>, update: array<string,mixed>, insert: array<string,mixed>} $args
     * @return array<string,mixed>
     */
    protected function doUpsert(array $args): array
    {
        $this->root?->flushCache();
        return $this->profile('upsert', function () use ($args): array {
            $existing = $this->doFindFirst(['where' => $args['where']]);
            if ($existing !== null) {
                return $this->doUpdate(['where' => $args['where'], 'data' => $args['update']]);
            }
            return $this->doInsert(['data' => $args['insert']]);
        });
    }

    /**
     * @param array{data: list<array<string,mixed>>, skipDuplicates?: bool} $args
     * @return array{count: int}
     */
    protected function doInsertMany(array $args): array
    {
        $this->root?->flushCache();
        return $this->profile('insertMany', function () use ($args): array {
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
        });
    }

    /**
     * @param array{where?: array<string,mixed>, data: array<string,mixed>} $args
     * @return array{count: int}
     */
    protected function doUpdateMany(array $args): array
    {
        $this->root?->flushCache();
        return $this->profile('updateMany', function () use ($args): array {
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
        });
    }

    /**
     * @param array{where?: array<string,mixed>} $args
     * @return array{count: int}
     */
    protected function doDeleteMany(array $args = []): array
    {
        $this->root?->flushCache();
        return $this->profile('deleteMany', function () use ($args): array {
            [$whereSql, $params] = $this->compileWhere($args['where'] ?? []);
            $sql = sprintf(
                'DELETE FROM %s WHERE %s',
                $this->driver->quoteIdent($this->table()),
                $whereSql,
            );
            $stmt = $this->driver->pdo()->prepare($sql);
            $stmt->execute($params);
            return ['count' => $stmt->rowCount()];
        });
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

        return $this->profile('count', function () use ($args, $cache, $cacheKey): int {
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
        });
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
        if ($relation->isManyToMany()) {
            return $this->loadManyToMany($rows, $name, $relation, $subArgs);
        }

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
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed>       $subArgs
     * @return list<array<string,mixed>>
     */
    private function loadManyToMany(array $rows, string $name, Relation $relation, array $subArgs): array
    {
        if ($this->root === null) {
            throw new \LogicException('include requires root client; was the model registered?');
        }
        if ($relation->joinTable === null || $relation->joinLocalColumn === null || $relation->joinForeignColumn === null) {
            throw new \LogicException("M2M relation '{$name}' is missing join metadata");
        }

        $localPk = $relation->localFields[0];
        $foreignPk = $relation->foreignFields[0];

        $localIds = [];
        foreach ($rows as $row) {
            $v = $row[$localPk] ?? null;
            if ($v !== null) {
                $localIds[] = $v;
            }
        }
        $localIds = array_values(array_unique($localIds, SORT_REGULAR));
        if ($localIds === []) {
            return $this->emptyRelationFill($rows, $name, $relation);
        }

        $marks = implode(', ', array_fill(0, count($localIds), '?'));
        $sql = sprintf(
            'SELECT %s, %s FROM %s WHERE %s IN (%s)',
            $this->driver->quoteIdent($relation->joinLocalColumn),
            $this->driver->quoteIdent($relation->joinForeignColumn),
            $this->driver->quoteIdent($relation->joinTable),
            $this->driver->quoteIdent($relation->joinLocalColumn),
            $marks,
        );
        $stmt = $this->driver->pdo()->prepare($sql);
        $stmt->execute($localIds);
        $joinRows = $stmt->fetchAll();

        $foreignIdsByLocal = [];
        $foreignIdSet = [];
        foreach ($joinRows as $jr) {
            $localId   = $jr[$relation->joinLocalColumn]   ?? null;
            $foreignId = $jr[$relation->joinForeignColumn] ?? null;
            if ($localId === null || $foreignId === null) {
                continue;
            }
            $foreignIdsByLocal[(string) $localId][] = $foreignId;
            $foreignIdSet[(string) $foreignId] = $foreignId;
        }

        if ($foreignIdSet === []) {
            return $this->emptyRelationFill($rows, $name, $relation);
        }

        $targetClient = $this->root->modelClient($relation->target);
        $where = $subArgs['where'] ?? [];
        $where[$foreignPk] = ['in' => array_values($foreignIdSet)];
        $subArgs['where'] = $where;
        $targets = $targetClient->doFindMany($subArgs);

        $targetsByPk = [];
        foreach ($targets as $t) {
            $k = $t[$foreignPk] ?? null;
            if ($k !== null) {
                $targetsByPk[(string) $k] = $t;
            }
        }

        foreach ($rows as $i => $row) {
            $localId = $row[$localPk] ?? null;
            if ($localId === null) {
                $rows[$i][$name] = [];
                continue;
            }
            $matched = [];
            foreach ($foreignIdsByLocal[(string) $localId] ?? [] as $fid) {
                $hit = $targetsByPk[(string) $fid] ?? null;
                if ($hit !== null) {
                    $matched[] = $hit;
                }
            }
            $rows[$i][$name] = $matched;
        }

        return $rows;
    }

    /**
     * Apply connect/disconnect/set for an M2M relation. The local PK value
     * must already be known (use after the parent INSERT or in UPDATE).
     *
     * @param array{connect?: list<array<string,mixed>>, disconnect?: list<array<string,mixed>>, set?: list<array<string,mixed>>} $spec
     */
    private function applyManyToManyMutation(Relation $relation, mixed $localId, array $spec): void
    {
        if ($relation->joinTable === null || $relation->joinLocalColumn === null || $relation->joinForeignColumn === null) {
            throw new \LogicException('M2M mutation requires join metadata');
        }
        $foreignPk = $relation->foreignFields[0];
        $localCol = $this->driver->quoteIdent($relation->joinLocalColumn);
        $foreignCol = $this->driver->quoteIdent($relation->joinForeignColumn);
        $table = $this->driver->quoteIdent($relation->joinTable);

        if (array_key_exists('set', $spec)) {
            $del = $this->driver->pdo()->prepare("DELETE FROM {$table} WHERE {$localCol} = ?");
            $del->execute([$localId]);
            $this->connectMany($table, $localCol, $foreignCol, $localId, $spec['set'], $foreignPk);
        }

        if (!empty($spec['disconnect'])) {
            $del = $this->driver->pdo()->prepare(
                "DELETE FROM {$table} WHERE {$localCol} = ? AND {$foreignCol} = ?"
            );
            foreach ($spec['disconnect'] as $where) {
                if (!array_key_exists($foreignPk, $where)) {
                    throw new \InvalidArgumentException(
                        "disconnect requires the foreign PK '{$foreignPk}' to be present"
                    );
                }
                $del->execute([$localId, $where[$foreignPk]]);
            }
        }

        if (!empty($spec['connect'])) {
            $this->connectMany($table, $localCol, $foreignCol, $localId, $spec['connect'], $foreignPk);
        }
    }

    /**
     * @param list<array<string,mixed>> $wheres
     */
    private function connectMany(string $table, string $localCol, string $foreignCol, mixed $localId, array $wheres, string $foreignPk): void
    {
        if ($wheres === []) {
            return;
        }
        $ins = $this->driver->pdo()->prepare(
            "INSERT INTO {$table} ({$localCol}, {$foreignCol}) VALUES (?, ?)"
        );
        foreach ($wheres as $where) {
            if (!array_key_exists($foreignPk, $where)) {
                throw new \InvalidArgumentException(
                    "connect/set requires the foreign PK '{$foreignPk}' to be present"
                );
            }
            $ins->execute([$localId, $where[$foreignPk]]);
        }
    }

    /**
     * Split `data` into (scalar columns to write, relation manipulations).
     *
     * @param array<string,mixed> $data
     * @return array{0: array<string,mixed>, 1: array<string, array<string,mixed>>}
     */
    private function partitionRelationData(array $data): array
    {
        $relations = $this->relations();
        $scalars = [];
        $rels = [];
        foreach ($data as $k => $v) {
            if (isset($relations[$k]) && is_array($v)) {
                $rels[$k] = $v;
            } else {
                $scalars[$k] = $v;
            }
        }
        return [$scalars, $rels];
    }

    /**
     * @param array<string, array<string,mixed>> $mutations
     * @param array<string,mixed>                $parentRow
     */
    private function applyRelationMutations(array $mutations, array $parentRow, string $mode): void
    {
        $relations = $this->relations();
        foreach ($mutations as $name => $spec) {
            $rel = $relations[$name] ?? null;
            if ($rel === null) {
                throw new \InvalidArgumentException("Unknown relation '{$name}' on " . $this->table());
            }
            if (!$rel->isManyToMany()) {
                throw new \InvalidArgumentException(
                    "Relation manipulation is currently only supported for many-to-many ('{$name}' is {$rel->kind})"
                );
            }
            $localPk = $rel->localFields[0];
            $localId = $parentRow[$localPk] ?? null;
            if ($localId === null) {
                throw new \LogicException("Cannot manipulate '{$name}': parent row has no value for '{$localPk}'");
            }
            if ($mode === 'insert' && (isset($spec['disconnect']) || array_key_exists('set', $spec))) {
                throw new \InvalidArgumentException(
                    "insert only supports 'connect' on M2M relations (got '{$name}')"
                );
            }
            $this->applyManyToManyMutation($rel, $localId, $spec);
        }
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
     * Accepts both shorthand list form (`['id', 'email']`) and map form
     * (`['id' => true, 'email' => true]`). PK is auto-included.
     *
     * @param array<string,bool>|list<string>|null $select
     * @return list<string>
     */
    private function resolveSelect(?array $select): array
    {
        if ($select === null) {
            return $this->columns();
        }
        $valid = array_flip($this->columns());
        $picked = [];

        if (array_is_list($select)) {
            foreach ($select as $col) {
                if (is_string($col) && isset($valid[$col])) {
                    $picked[] = $col;
                }
            }
        } else {
            foreach ($select as $col => $on) {
                if (!is_string($col) || !$on) {
                    continue;
                }
                if (!isset($valid[$col])) {
                    continue;
                }
                $picked[] = $col;
            }
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
