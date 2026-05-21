<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Client;

/**
 * Resolved relation metadata used at runtime to load `include` requests
 * and to mutate join rows from connect/disconnect/set in insert/update.
 *
 * Kinds:
 *  - belongsTo:  this side holds the FK; result is a single row (nullable).
 *  - hasOne:     the other side holds the FK; result is a single row.
 *  - hasMany:    the other side holds the FK; result is a list.
 *  - manyToMany: edge stored in a separate join table _AToB; result is a list.
 *
 * For belongsTo/hasOne/hasMany:
 *   localFields   = columns on this model
 *   foreignFields = columns on the target model that they match
 *   (belongsTo: this=FK, other=PK; hasMany: this=PK, other=FK)
 *
 * For manyToMany:
 *   localFields    = ['<this model's PK column>']
 *   foreignFields  = ['<target model's PK column>']
 *   joinTable      = '_AToB' (models sorted alphabetically)
 *   joinLocalColumn   = 'A' or 'B' (this side's column in the join table)
 *   joinForeignColumn = the other letter
 */
final class Relation
{
    public const BELONGS_TO   = 'belongsTo';
    public const HAS_ONE      = 'hasOne';
    public const HAS_MANY     = 'hasMany';
    public const MANY_TO_MANY = 'manyToMany';

    /**
     * @param list<string> $localFields
     * @param list<string> $foreignFields
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $target,
        public readonly array $localFields,
        public readonly array $foreignFields,
        public readonly ?string $joinTable = null,
        public readonly ?string $joinLocalColumn = null,
        public readonly ?string $joinForeignColumn = null,
    ) {}

    public function isList(): bool
    {
        return $this->kind === self::HAS_MANY || $this->kind === self::MANY_TO_MANY;
    }

    public function isManyToMany(): bool
    {
        return $this->kind === self::MANY_TO_MANY;
    }
}
