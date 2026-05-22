<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Schema;

/**
 * A single-column foreign key read back during introspection: the local column
 * references `referencedColumn` on `referencedTable`.
 */
final class IntrospectedForeignKey
{
    public function __construct(
        public readonly string $column,
        public readonly string $referencedTable,
        public readonly string $referencedColumn,
    ) {}
}
