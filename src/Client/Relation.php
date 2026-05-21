<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Client;

/**
 * Resolved relation metadata used at runtime to load `include` requests.
 *
 * - `belongsTo`: this side holds the FK; result is a single row (nullable).
 * - `hasOne`:    the other side holds the FK; result is a single row.
 * - `hasMany`:   the other side holds the FK; result is a list.
 *
 * `localFields` are the columns on *this* model, `foreignFields` are the
 * columns on the target model that they match. (For belongsTo: this side
 * = fk, other side = pk; for hasMany: this side = pk, other side = fk.)
 */
final class Relation
{
    public const BELONGS_TO = 'belongsTo';
    public const HAS_ONE    = 'hasOne';
    public const HAS_MANY   = 'hasMany';

    /**
     * @param list<string> $localFields
     * @param list<string> $foreignFields
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $target,
        public readonly array $localFields,
        public readonly array $foreignFields,
    ) {}

    public function isList(): bool
    {
        return $this->kind === self::HAS_MANY;
    }
}
