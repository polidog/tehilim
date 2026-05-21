<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Generator;

use Polidog\Tehilim\Schema\Ast\Field;

final class TypeFormatter
{
    public static function phpType(Field $field): string
    {
        $base = match ($field->type->name) {
            'Int', 'BigInt' => 'int',
            'Float', 'Decimal' => 'float',
            'Boolean' => 'bool',
            'String', 'Bytes' => 'string',
            'DateTime' => '\\DateTimeImmutable',
            'Json' => 'mixed',
            default => 'mixed',
        };
        if ($base === 'mixed') {
            return 'mixed';
        }
        return $field->nullable ? "{$base}|null" : $base;
    }

    /** PHPStan-friendly internal type tag used by BaseModelClient::COLUMN_TYPES. */
    public static function columnType(Field $field): string
    {
        return match ($field->type->name) {
            'Int' => 'int',
            'BigInt' => 'BigInt',
            'Float', 'Decimal' => 'float',
            'Boolean' => 'bool',
            'String' => 'string',
            'DateTime' => 'DateTime',
            'Json' => 'json',
            'Bytes' => 'bytes',
            default => 'string',
        };
    }
}
