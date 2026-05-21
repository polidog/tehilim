<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Migration;

final class ColumnDef
{
    public function __construct(
        public readonly string $name,
        public readonly string $phpType,
        public readonly bool $nullable = false,
        public readonly bool $autoIncrement = false,
        public readonly mixed $default = null,
    ) {}
}
