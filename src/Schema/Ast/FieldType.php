<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Schema\Ast;

final class FieldType
{
    public const SCALARS = ['Int', 'String', 'Boolean', 'DateTime', 'Float', 'Json', 'Bytes', 'BigInt', 'Decimal'];

    public function __construct(
        public readonly string $name,
    ) {}

    public function isScalar(): bool
    {
        return in_array($this->name, self::SCALARS, true);
    }
}
