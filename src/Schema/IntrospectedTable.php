<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Schema;

/**
 * A table read back from a live database during introspection.
 *
 * `compositePrimaryKey` is set only when the PK spans 2+ columns (a single-
 * column PK is flagged on the {@see IntrospectedColumn} instead). Likewise
 * `compositeUniques` holds only multi-column unique groups; single-column
 * uniques live on the column.
 */
final class IntrospectedTable
{
    /**
     * @param list<IntrospectedColumn>     $columns
     * @param null|list<string>            $compositePrimaryKey
     * @param list<list<string>>           $compositeUniques
     * @param list<IntrospectedForeignKey> $foreignKeys         single-column foreign keys
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly ?array $compositePrimaryKey = null,
        public readonly array $compositeUniques = [],
        public readonly array $foreignKeys = [],
    ) {}
}
