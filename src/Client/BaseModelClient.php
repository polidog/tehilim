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

    public function __construct(protected readonly Driver $driver)
    {
        $this->whereCompiler = new WhereCompiler();
    }

    abstract protected function table(): string;

    abstract protected function primaryKey(): ?string;

    /** @return list<string> */
    abstract protected function columns(): array;

    /** @return array<string,string> */
    abstract protected function columnTypes(): array;

    /**
     * @param array{where: array<string,mixed>} $args
     * @return array<string,mixed>|null
     */
    protected function doFindUnique(array $args): ?array
    {
        return $this->doFindFirst($args);
    }

    /**
     * @param array{where?: array<string,mixed>, orderBy?: array<string,string>|list<array<string,string>>, take?: int, skip?: int} $args
     * @return array<string,mixed>|null
     */
    protected function doFindFirst(array $args): ?array
    {
        $args['take'] = 1;
        $rows = $this->doFindMany($args);
        return $rows[0] ?? null;
    }

    /**
     * @param array{where?: array<string,mixed>, orderBy?: array<string,string>|list<array<string,string>>, take?: int, skip?: int} $args
     * @return list<array<string,mixed>>
     */
    protected function doFindMany(array $args = []): array
    {
        [$whereSql, $params] = $this->compileWhere($args['where'] ?? []);

        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s',
            $this->selectColumns(),
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
        return $out;
    }

    /**
     * @param array{data: array<string,mixed>} $args
     * @return array<string,mixed>
     */
    protected function doCreate(array $args): array
    {
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
     * @param array{where?: array<string,mixed>} $args
     */
    protected function doCount(array $args = []): int
    {
        [$whereSql, $params] = $this->compileWhere($args['where'] ?? []);
        $sql = sprintf(
            'SELECT COUNT(*) AS c FROM %s WHERE %s',
            $this->driver->quoteIdent($this->table()),
            $whereSql,
        );
        $stmt = $this->driver->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return (int) ($row['c'] ?? 0);
    }

    /**
     * @param array<string,mixed> $where
     * @return array{0:string,1:list<mixed>}
     */
    private function compileWhere(array $where): array
    {
        return $this->whereCompiler->compile($where, $this->driver, $this->columnTypes());
    }

    private function selectColumns(): string
    {
        $cols = $this->columns();
        if ($cols === []) {
            return '*';
        }
        return implode(', ', array_map($this->driver->quoteIdent(...), $cols));
    }

    /**
     * @param array<string,string>|list<array<string,string>>|null $orderBy
     */
    private function orderByClause(mixed $orderBy): string
    {
        if ($orderBy === null) {
            return '';
        }
        if (!is_array($orderBy) || $orderBy === []) {
            return '';
        }
        $list = is_int(array_key_first($orderBy)) ? $orderBy : [$orderBy];

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
