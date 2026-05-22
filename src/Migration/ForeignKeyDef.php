<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Migration;

/**
 * A single-column foreign key emitted as part of a table's DDL: `column`
 * references `referencedColumn` on `referencedTable`.
 */
final class ForeignKeyDef
{
    public function __construct(
        public readonly string $column,
        public readonly string $referencedTable,
        public readonly string $referencedColumn,
    ) {}
}
