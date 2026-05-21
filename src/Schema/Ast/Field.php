<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Schema\Ast;

final class Field
{
    /**
     * @param list<Attribute> $attributes
     */
    public function __construct(
        public readonly string $name,
        public readonly FieldType $type,
        public readonly bool $nullable = false,
        public readonly bool $list = false,
        public readonly array $attributes = [],
    ) {}

    public function attribute(string $name): ?Attribute
    {
        return array_find($this->attributes, static fn (Attribute $a): bool => $a->name === $name);
    }

    public function hasAttribute(string $name): bool
    {
        return $this->attribute($name) !== null;
    }

    public function columnName(): string
    {
        $map = $this->attribute('map');
        if ($map !== null && isset($map->args[0]) && is_string($map->args[0])) {
            return $map->args[0];
        }
        return $this->name;
    }

    public function isGenerated(): bool
    {
        $default = $this->attribute('default');
        if ($default === null) {
            return false;
        }
        $val = $default->args[0] ?? null;
        if ($val instanceof Invocation) {
            return in_array($val->name, ['autoincrement', 'uuid', 'cuid'], true);
        }
        return false;
    }
}
