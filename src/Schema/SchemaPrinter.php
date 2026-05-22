<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Schema;

use Polidog\Tehilim\Schema\Ast\Attribute;
use Polidog\Tehilim\Schema\Ast\BlockAttribute;
use Polidog\Tehilim\Schema\Ast\Datasource;
use Polidog\Tehilim\Schema\Ast\Field;
use Polidog\Tehilim\Schema\Ast\Generator;
use Polidog\Tehilim\Schema\Ast\Invocation;
use Polidog\Tehilim\Schema\Ast\Model;
use Polidog\Tehilim\Schema\Ast\Schema;

/**
 * Renders a Schema AST back into `.tehilim` source text. The output round-trips
 * through {@see Parser}: printing then re-parsing yields an equivalent Schema.
 */
final class SchemaPrinter
{
    private const IDENT = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public function print(Schema $schema): string
    {
        $blocks = [];

        foreach ($schema->datasources as $ds) {
            $blocks[] = $this->printDatasource($ds);
        }
        foreach ($schema->generators as $gen) {
            $blocks[] = $this->printGenerator($gen);
        }
        foreach ($schema->models as $model) {
            $blocks[] = $this->printModel($model);
        }

        return implode("\n\n", $blocks) . "\n";
    }

    private function printDatasource(Datasource $ds): string
    {
        return "datasource {$ds->name} {\n" . $this->printAssignments($ds->options) . '}';
    }

    private function printGenerator(Generator $gen): string
    {
        return "generator {$gen->name} {\n" . $this->printAssignments($gen->options) . '}';
    }

    /**
     * @param array<string,mixed> $options
     */
    private function printAssignments(array $options): string
    {
        $out = '';
        foreach ($options as $key => $value) {
            $out .= '  ' . $key . ' = ' . $this->renderValue($value, false) . "\n";
        }

        return $out;
    }

    private function printModel(Model $model): string
    {
        $lines = [];
        foreach ($model->fields as $field) {
            $lines[] = '  ' . $this->printField($field);
        }
        foreach ($model->blockAttributes as $ba) {
            $lines[] = '  ' . $this->printBlockAttribute($ba);
        }

        return "model {$model->name} {\n" . implode("\n", $lines) . "\n}";
    }

    private function printField(Field $field): string
    {
        $type = $field->type->name;
        if ($field->list) {
            $type .= '[]';
        }
        if ($field->nullable) {
            $type .= '?';
        }

        $parts = [$field->name, $type];
        foreach ($field->attributes as $attr) {
            $parts[] = $this->printAttribute($attr);
        }

        return implode(' ', $parts);
    }

    private function printAttribute(Attribute $attr): string
    {
        if ($attr->args === []) {
            return '@' . $attr->name;
        }

        return '@' . $attr->name . '(' . $this->renderArgs($attr->args) . ')';
    }

    private function printBlockAttribute(BlockAttribute $ba): string
    {
        if ($ba->args === []) {
            return '@@' . $ba->name;
        }

        return '@@' . $ba->name . '(' . $this->renderArgs($ba->args) . ')';
    }

    /**
     * @param array<int|string,mixed> $args
     */
    private function renderArgs(array $args): string
    {
        $parts = [];
        foreach ($args as $key => $value) {
            $rendered = $this->renderValue($value, false);
            $parts[] = is_string($key) ? "{$key}: {$rendered}" : $rendered;
        }

        return implode(', ', $parts);
    }

    /**
     * $inList lets bare column identifiers inside `@@id([a, b])` / `@@unique`
     * stay unquoted, matching idiomatic schema style.
     */
    private function renderValue(mixed $value, bool $inList): string
    {
        if ($value instanceof Invocation) {
            $args = implode(', ', array_map(fn (mixed $a): string => $this->renderValue($a, false), $value->args));

            return $value->name . '(' . $args . ')';
        }
        if (is_array($value)) {
            $items = array_map(fn (mixed $a): string => $this->renderValue($a, true), $value);

            return '[' . implode(', ', $items) . ']';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $str = (string) $value;
        if ($inList && preg_match(self::IDENT, $str) === 1) {
            return $str;
        }

        return '"' . $this->escapeString($str) . '"';
    }

    private function escapeString(string $s): string
    {
        return str_replace(
            ['\\', '"', "\n", "\t", "\r"],
            ['\\\\', '\"', '\n', '\t', '\r'],
            $s,
        );
    }
}
