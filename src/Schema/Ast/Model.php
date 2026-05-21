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
        return array_find($this->fields, static fn (Field $f): bool => $f->name === $name);
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
        return array_find(
            $this->scalarFields(),
            static fn (Field $f): bool => $f->hasAttribute('id'),
        );
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
        $mapBa = array_find(
            $this->blockAttributes,
            static fn (BlockAttribute $ba): bool =>
                $ba->name === 'map' && isset($ba->args[0]) && is_string($ba->args[0]),
        );
        return $mapBa !== null ? (string) $mapBa->args[0] : $this->name;
    }

    /** @return list<string>|null */
    public function compositePrimaryKey(): ?array
    {
        $idBa = array_find(
            $this->blockAttributes,
            static fn (BlockAttribute $ba): bool => $ba->name === 'id' && is_array($ba->args[0] ?? null),
        );
        if ($idBa === null) {
            return null;
        }
        $cols = array_values(array_filter((array) $idBa->args[0], is_string(...)));
        return $cols === [] ? null : $cols;
    }

    /** @return list<list<string>> */
    public function compositeUniqueGroups(): array
    {
        $out = [];
        foreach ($this->blockAttributes as $ba) {
            if ($ba->name !== 'unique') {
                continue;
            }
            $val = $ba->args[0] ?? null;
            if (!is_array($val)) {
                continue;
            }
            $cols = array_values(array_filter($val, is_string(...)));
            if (count($cols) >= 2) {
                $out[] = $cols;
            }
        }
        return $out;
    }
}
