<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Migration;

final class TableDef
{
    /**
     * @param list<ColumnDef> $columns
     * @param list<string>    $uniqueColumns
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly ?string $primaryKey = null,
        public readonly array $uniqueColumns = [],
    ) {}
}
