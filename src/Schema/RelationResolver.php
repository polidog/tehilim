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
            /** @var list<string> $fields */
            $fields = $this->stringList($rel->args['fields'] ?? []);
            /** @var list<string> $references */
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

        foreach ($target->relationFields() as $tf) {
            if ($tf->type->name !== $model->name) {
                continue;
            }
            $r = $tf->attribute('relation');
            if ($r === null) {
                continue;
            }
            $foreignFields = $this->stringList($r->args['fields'] ?? []);
            $localFields = $this->stringList($r->args['references'] ?? []);
            if ($foreignFields === [] || $localFields === []) {
                continue;
            }
            return new Relation(
                kind: $field->list ? Relation::HAS_MANY : Relation::HAS_ONE,
                target: $target->name,
                localFields: $localFields,
                foreignFields: $foreignFields,
            );
        }

        throw new ParseException(
            "Relation '{$model->name}.{$field->name}' has no @relation here and no inverse @relation on {$target->name}"
        );
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $v) {
            if (is_string($v)) {
                $out[] = $v;
            }
        }
        return $out;
    }
}
