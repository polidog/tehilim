<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Schema;

/**
 * A single column read back from a live database during introspection.
 * `tehilimType` is a schema-level type name (Int, String, DateTime, …), already
 * mapped from the dialect-specific column type by the driver.
 */
final class IntrospectedColumn
{
    public function __construct(
        public readonly string $name,
        public readonly string $tehilimType,
        public readonly bool $nullable = false,
        public readonly bool $autoIncrement = false,
        public readonly bool $primaryKey = false,
        public readonly bool $unique = false,
    ) {}
}
