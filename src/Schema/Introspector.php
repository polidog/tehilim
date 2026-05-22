<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Schema;

use Polidog\Tehilim\Driver\Driver;
use Polidog\Tehilim\Schema\Ast\Attribute;
use Polidog\Tehilim\Schema\Ast\BlockAttribute;
use Polidog\Tehilim\Schema\Ast\Field;
use Polidog\Tehilim\Schema\Ast\FieldType;
use Polidog\Tehilim\Schema\Ast\Invocation;
use Polidog\Tehilim\Schema\Ast\Model;
use Polidog\Tehilim\Schema\Ast\Schema;

/**
 * Reverse of code generation: reads a live database through a {@see Driver} and
 * reconstructs a Schema AST. Each table becomes a model with scalar fields;
 * primary keys, uniques (single + composite) and auto-increment are recovered.
 *
 * Foreign-key relations and implicit many-to-many join tables are NOT inferred
 * yet — join tables surface as plain models for now.
 */
final class Introspector
{
    /** Internal bookkeeping table that must never appear in the schema. */
    private const MIGRATIONS_TABLE = '_tehilim_migrations';

    public function __construct(private readonly Driver $driver) {}

    /**
     * Build a Schema from the live database. The datasource/generator blocks
     * are carried over from $template (typically the existing schema file) so a
     * `db pull` keeps the user's connection + output config intact.
     */
    public function introspect(?Schema $template = null): Schema
    {
        // Sort tables so `pull` output is deterministic — drivers don't ORDER BY
        // listTables(), so ordering would otherwise vary and create noisy diffs.
        $tables = $this->driver->listTables();
        sort($tables);

        $models = [];
        foreach ($tables as $table) {
            if ($table === self::MIGRATIONS_TABLE) {
                continue;
            }
            $models[] = $this->toModel($this->driver->introspectTable($table));
        }

        return new Schema(
            $models,
            $template !== null ? $template->datasources : [],
            $template !== null ? $template->generators : [],
        );
    }

    private function toModel(IntrospectedTable $table): Model
    {
        $fields = array_map($this->toField(...), $table->columns);

        $blockAttrs = [];
        if ($table->compositePrimaryKey !== null) {
            $blockAttrs[] = new BlockAttribute('id', [$table->compositePrimaryKey]);
        }
        foreach ($table->compositeUniques as $group) {
            $blockAttrs[] = new BlockAttribute('unique', [$group]);
        }

        return new Model($table->name, $fields, $blockAttrs);
    }

    private function toField(IntrospectedColumn $col): Field
    {
        $attributes = [];
        if ($col->primaryKey) {
            $attributes[] = new Attribute('id');
        }
        if ($col->unique && !$col->primaryKey) {
            $attributes[] = new Attribute('unique');
        }
        if ($col->autoIncrement) {
            $attributes[] = new Attribute('default', [new Invocation('autoincrement')]);
        }

        return new Field(
            name: $col->name,
            type: new FieldType($col->tehilimType),
            nullable: $col->nullable,
            list: false,
            attributes: $attributes,
        );
    }
}
