<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Migration;

final class TableDef
{
    /**
     * @param list<ColumnDef>     $columns
     * @param list<string>        $uniqueColumns         single-column uniques
     * @param null|list<string>   $compositePrimaryKey   if set, primaryKey is ignored
     * @param list<list<string>>  $compositeUniqueGroups multi-column uniques (≥ 2 cols)
     * @param list<ForeignKeyDef> $foreignKeys           single-column foreign keys
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly ?string $primaryKey = null,
        public readonly array $uniqueColumns = [],
        public readonly ?array $compositePrimaryKey = null,
        public readonly array $compositeUniqueGroups = [],
        public readonly array $foreignKeys = [],
    ) {}

    /** @return list<string> the effective primary key columns (composite or single) */
    public function pkColumns(): array
    {
        if ($this->compositePrimaryKey !== null) {
            return $this->compositePrimaryKey;
        }

        return $this->primaryKey === null ? [] : [$this->primaryKey];
    }
}
