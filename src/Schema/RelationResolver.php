<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Schema;

use Polidog\Tehilim\Client\Relation;
use Polidog\Tehilim\Schema\Ast\Field;
use Polidog\Tehilim\Schema\Ast\Model;
use Polidog\Tehilim\Schema\Ast\Schema;

final class RelationResolver
{
    public function __construct(private readonly Schema $schema) {}

    public function resolve(Model $model, Field $field): Relation
    {
        if ($field->type->isScalar()) {
            throw new \LogicException("Field '{$field->name}' on {$model->name} is scalar, not a relation");
        }

        $target = $this->schema->model($field->type->name);
        if ($target === null) {
            throw new ParseException("Unknown model '{$field->type->name}' referenced by {$model->name}.{$field->name}");
        }

        $rel = $field->attribute('relation');
        if ($rel !== null) {
            $fields = $this->stringList($rel->args['fields'] ?? []);
            $references = $this->stringList($rel->args['references'] ?? []);
            if ($fields === [] || $references === []) {
                throw new ParseException("@relation on {$model->name}.{$field->name} requires 'fields' and 'references'");
            }
            return new Relation(
                kind: Relation::BELONGS_TO,
                target: $target->name,
                localFields: $fields,
                foreignFields: $references,
            );
        }

        $inverse = array_find(
            $target->relationFields(),
            static fn (Field $tf): bool =>
                $tf->type->name === $model->name && $tf->attribute('relation') !== null,
        );
        if ($inverse !== null) {
            /** @var \Polidog\Tehilim\Schema\Ast\Attribute $r */
            $r = $inverse->attribute('relation');
            $foreignFields = $this->stringList($r->args['fields'] ?? []);
            $localFields = $this->stringList($r->args['references'] ?? []);
            if ($foreignFields !== [] && $localFields !== []) {
                return new Relation(
                    kind: $field->list ? Relation::HAS_MANY : Relation::HAS_ONE,
                    target: $target->name,
                    localFields: $localFields,
                    foreignFields: $foreignFields,
                );
            }
        }

        if ($field->list) {
            $m2mBack = array_find(
                $target->relationFields(),
                static fn (Field $tf): bool =>
                    $tf->type->name === $model->name
                    && $tf->list
                    && $tf->attribute('relation') === null,
            );
            if ($m2mBack !== null) {
                return $this->buildManyToMany($model, $target);
            }
        }

        throw new ParseException(
            "Relation '{$model->name}.{$field->name}' has no @relation here and no inverse @relation on {$target->name}"
        );
    }

    private function buildManyToMany(Model $model, Model $target): Relation
    {
        $localPk = $model->primaryKey();
        $foreignPk = $target->primaryKey();
        if ($localPk === null || $foreignPk === null) {
            throw new ParseException(
                "Implicit M2M between {$model->name} and {$target->name} requires a single-column @id on each side"
            );
        }

        [$first, $second] = $model->name < $target->name
            ? [$model->name, $target->name]
            : [$target->name, $model->name];

        $joinTable = "_{$first}To{$second}";
        $isFirst = $model->name === $first;

        return new Relation(
            kind: Relation::MANY_TO_MANY,
            target: $target->name,
            localFields: [$localPk->columnName()],
            foreignFields: [$foreignPk->columnName()],
            joinTable: $joinTable,
            joinLocalColumn: $isFirst ? 'A' : 'B',
            joinForeignColumn: $isFirst ? 'B' : 'A',
        );
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, is_string(...)));
    }
}
