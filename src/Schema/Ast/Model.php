<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Schema\Ast;

final class Model
{
    /**
     * @param list<Field>          $fields
     * @param list<BlockAttribute> $blockAttributes
     */
    public function __construct(
        public readonly string $name,
        public readonly array $fields,
        public readonly array $blockAttributes = [],
    ) {}

    public function field(string $name): ?Field
    {
        foreach ($this->fields as $f) {
            if ($f->name === $name) {
                return $f;
            }
        }
        return null;
    }

    /** @return list<Field> */
    public function scalarFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            static fn (Field $f): bool => $f->type->isScalar(),
        ));
    }

    /** @return list<Field> */
    public function relationFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            static fn (Field $f): bool => !$f->type->isScalar(),
        ));
    }

    public function primaryKey(): ?Field
    {
        foreach ($this->scalarFields() as $f) {
            if ($f->hasAttribute('id')) {
                return $f;
            }
        }
        return null;
    }

    /** @return list<Field> */
    public function uniqueFields(): array
    {
        return array_values(array_filter(
            $this->scalarFields(),
            static fn (Field $f): bool => $f->hasAttribute('unique') || $f->hasAttribute('id'),
        ));
    }

    public function tableName(): string
    {
        foreach ($this->blockAttributes as $ba) {
            if ($ba->name === 'map' && isset($ba->args[0]) && is_string($ba->args[0])) {
                return $ba->args[0];
            }
        }
        return $this->name;
    }
}
