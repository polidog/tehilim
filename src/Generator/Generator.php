<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Generator;

use Polidog\Tehilim\Client\Relation;
use Polidog\Tehilim\Schema\Ast\Field;
use Polidog\Tehilim\Schema\Ast\Model;
use Polidog\Tehilim\Schema\Ast\Schema;
use Polidog\Tehilim\Schema\RelationResolver;
use RuntimeException;
use Throwable;

final class Generator
{
    /**
     * PHP keywords that cannot be used as a class name. A model named after one
     * of these would produce uncompilable generated code (e.g. `final class
     * match extends ...`), so reject it up front with a clear message.
     */
    private const RESERVED_NAMES = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch',
        'class', 'clone', 'const', 'continue', 'declare', 'default', 'do', 'echo',
        'else', 'elseif', 'empty', 'enddeclare', 'endfor', 'endforeach', 'endif',
        'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'final',
        'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if',
        'implements', 'include', 'include_once', 'instanceof', 'insteadof',
        'interface', 'isset', 'list', 'match', 'namespace', 'new', 'or', 'print',
        'private', 'protected', 'public', 'readonly', 'require', 'require_once',
        'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use',
        'var', 'while', 'xor', 'yield',
        'bool', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null',
        'object', 'parent', 'self', 'string', 'true', 'void',
    ];

    private readonly RelationResolver $resolver;

    public function __construct(
        private readonly Schema $schema,
        private readonly string $outputDir,
        private readonly string $namespace,
        private readonly string $clientClass = 'TehilimClient',
    ) {
        $this->resolver = new RelationResolver($schema);
    }

    public function generate(): void
    {
        foreach ($this->schema->models as $model) {
            if (in_array(strtolower($model->name), self::RESERVED_NAMES, true)) {
                throw new RuntimeException(
                    "Model name '{$model->name}' is a reserved PHP keyword and cannot be used as a generated class name.",
                );
            }
        }

        $modelDir = $this->outputDir . '/Model';
        if (!is_dir($modelDir) && !mkdir($modelDir, 0755, true) && !is_dir($modelDir)) {
            throw new RuntimeException("Cannot create directory: {$modelDir}");
        }

        foreach ($this->schema->models as $model) {
            $path = $modelDir . '/' . $model->name . '.php';
            file_put_contents($path, $this->renderModelClient($model));
        }

        $rootPath = $this->outputDir . '/' . $this->clientClass . '.php';
        file_put_contents($rootPath, $this->renderRootClient());
    }

    private function renderRootClient(): string
    {
        $models = $this->schema->models;

        $properties = '';
        $assigns = '';
        $uses = "use PDO;\nuse Polidog\\Tehilim\\Client\\BaseClient;\nuse Polidog\\Tehilim\\Client\\IsolationLevel;\nuse Polidog\\Tehilim\\Config;\nuse Polidog\\Tehilim\\Driver\\Driver;\nuse Polidog\\Tehilim\\Driver\\Drivers;\n";

        foreach ($models as $m) {
            $cls = $m->name;
            $prop = lcfirst($m->name);
            $uses .= "use {$this->namespace}\\Model\\{$cls};\n";
            $properties .= "    public readonly {$cls} \${$prop};\n";
            $assigns .= "        \$this->{$prop} = new {$cls}(\$driver);\n";
            $assigns .= "        \$this->registerModel('{$m->name}', \$this->{$prop});\n";
        }

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$this->namespace};

{$uses}
final class {$this->clientClass} extends BaseClient
{
{$properties}
    public function __construct(Driver \$driver)
    {
        parent::__construct(\$driver);
{$assigns}    }

    /**
     * Build a client from an already-configured PDO instance.
     * The driver is inferred from PDO::ATTR_DRIVER_NAME.
     */
    public static function fromPdo(PDO \$pdo): self
    {
        return new self(Drivers::forPdo(\$pdo));
    }

    /**
     * Convenience: parse a URL into a PDO then build the client.
     * For full control over PDO attributes, use fromPdo() instead.
     */
    public static function fromUrl(string \$url, ?string \$user = null, ?string \$password = null): self
    {
        return self::fromPdo(Config::pdo(\$url, \$user, \$password));
    }

    /**
     * @template T
     * @param callable(self): T \$fn
     * @param ?IsolationLevel \$isolation isolation level for the top-level
     *        transaction (driver-dependent); must be null on nested calls.
     * @return T|mixed
     */
    public function transaction(callable \$fn, ?IsolationLevel \$isolation = null): mixed
    {
        return parent::transaction(\$fn, \$isolation);
    }
}

PHP;
    }

    private function renderModelClient(Model $model): string
    {
        $name = $model->name;
        $table = $model->tableName();
        $pk = $model->primaryKey();
        $pkName = $pk?->columnName();

        $relations = $this->resolveRelations($model);

        $imports = $this->renderTypeImports($relations);
        $rowScalarShape = $this->rowScalarShape($model);
        $rowShape = $this->rowShape($model, $relations);
        $insertShape = $this->insertInputShape($model, $relations);
        $updateShape = $this->updateInputShape($model, $relations);
        $whereUniqueShape = $this->whereUniqueShape($model);
        $includeShape = $this->includeShape($name, $relations);
        $selectShape = $this->selectShape($model);

        $columnsArray = $this->phpArrayList(array_map(
            static fn (Field $f): string => $f->columnName(),
            $model->scalarFields(),
        ));

        $columnTypesArray = $this->phpAssoc(array_combine(
            array_map(static fn (Field $f): string => $f->columnName(), $model->scalarFields()),
            array_map(static fn (Field $f): string => TypeFormatter::columnType($f), $model->scalarFields()),
        ));

        $primaryConst = $pkName === null ? 'null' : var_export($pkName, true);
        $primaryKeyReturnType = $pkName === null ? '?string' : 'string';

        $relationsMethod = $this->renderRelationsMethod($relations);

        $extraImports = $relations === [] ? '' : "\nuse Polidog\\Tehilim\\Client\\Relation;";

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$this->namespace}\\Model;

use Polidog\\Tehilim\\Client\\BaseModelClient;{$extraImports}

/**
{$imports} * @phpstan-type {$name}RowScalar {$rowScalarShape}
 * @phpstan-type {$name}Row {$rowShape}
 * @phpstan-type {$name}InsertInput {$insertShape}
 * @phpstan-type {$name}UpdateInput {$updateShape}
 * @phpstan-type {$name}WhereUnique {$whereUniqueShape}
 * @phpstan-type {$name}WhereInput array<string,mixed>
 * @phpstan-type {$name}OrderBy array<string,'asc'|'desc'>|list<array<string,'asc'|'desc'>>
 * @phpstan-type {$name}Include {$includeShape}
 * @phpstan-type {$name}Select {$selectShape}
 */
final class {$name} extends BaseModelClient
{
    public const ?string PK = {$primaryConst};

    protected function table(): string
    {
        return '{$table}';
    }

    protected function primaryKey(): {$primaryKeyReturnType}
    {
        return {$primaryConst};
    }

    /** @return list<string> */
    protected function columns(): array
    {
        return {$columnsArray};
    }

    /** @return array<string,string> */
    protected function columnTypes(): array
    {
        return {$columnTypesArray};
    }

{$relationsMethod}
    /**
     * @param array{where: {$name}WhereUnique, include?: {$name}Include, select?: {$name}Select} \$args
     * @return {$name}Row|null
     */
    public function findUnique(array \$args): ?array
    {
        return \$this->narrowOptionalRow(\$this->doFindUnique(\$args));
    }

    /**
     * @param array{where?: {$name}WhereInput, orderBy?: {$name}OrderBy, take?: int, skip?: int, include?: {$name}Include, select?: {$name}Select} \$args
     * @return {$name}Row|null
     */
    public function findFirst(array \$args = []): ?array
    {
        return \$this->narrowOptionalRow(\$this->doFindFirst(\$args));
    }

    /**
     * @param array{where?: {$name}WhereInput, orderBy?: {$name}OrderBy, take?: int, skip?: int, include?: {$name}Include, select?: {$name}Select} \$args
     * @return list<{$name}Row>
     */
    public function findMany(array \$args = []): array
    {
        return \$this->narrowRows(\$this->doFindMany(\$args));
    }

    /**
     * @param array{data: {$name}InsertInput} \$args
     * @return {$name}Row
     */
    public function insert(array \$args): array
    {
        return \$this->narrowRow(\$this->doInsert(\$args));
    }

    /**
     * @param array{where: {$name}WhereUnique, data: {$name}UpdateInput} \$args
     * @return {$name}Row
     */
    public function update(array \$args): array
    {
        return \$this->narrowRow(\$this->doUpdate(\$args));
    }

    /**
     * @param array{where: {$name}WhereUnique} \$args
     * @return {$name}Row
     */
    public function delete(array \$args): array
    {
        return \$this->narrowRow(\$this->doDelete(\$args));
    }

    /**
     * @param array{where?: {$name}WhereInput} \$args
     */
    public function count(array \$args = []): int
    {
        return \$this->doCount(\$args);
    }

    /**
     * @param array{where: {$name}WhereUnique, update: {$name}UpdateInput, insert: {$name}InsertInput} \$args
     * @return {$name}Row
     */
    public function upsert(array \$args): array
    {
        return \$this->narrowRow(\$this->doUpsert(\$args));
    }

    /**
     * @param array{data: list<{$name}InsertInput>, skipDuplicates?: bool} \$args
     * @return array{count: int}
     */
    public function insertMany(array \$args): array
    {
        return \$this->doInsertMany(\$args);
    }

    /**
     * @param array{where?: {$name}WhereInput, data: {$name}UpdateInput} \$args
     * @return array{count: int}
     */
    public function updateMany(array \$args): array
    {
        return \$this->doUpdateMany(\$args);
    }

    /**
     * @param array{where?: {$name}WhereInput} \$args
     * @return array{count: int}
     */
    public function deleteMany(array \$args = []): array
    {
        return \$this->doDeleteMany(\$args);
    }

    /**
     * @param array<string,mixed> \$row
     * @return {$name}Row
     */
    private function narrowRow(array \$row): array
    {
        // DB row shape comes from PDO + columnTypes(); trusted to match {$name}Row.
        /** @phpstan-ignore return.type */
        return \$row;
    }

    /**
     * @param array<string,mixed>|null \$row
     * @return {$name}Row|null
     */
    private function narrowOptionalRow(?array \$row): ?array
    {
        // DB row shape comes from PDO + columnTypes(); trusted to match {$name}Row.
        /** @phpstan-ignore return.type */
        return \$row;
    }

    /**
     * @param list<array<string,mixed>> \$rows
     * @return list<{$name}Row>
     */
    private function narrowRows(array \$rows): array
    {
        // DB row shape comes from PDO + columnTypes(); trusted to match {$name}Row.
        /** @phpstan-ignore return.type */
        return \$rows;
    }
}

PHP;
    }

    /**
     * @return array<string, array{relation:Relation, field:Field}>
     */
    private function resolveRelations(Model $model): array
    {
        $out = [];
        foreach ($model->relationFields() as $field) {
            try {
                $rel = $this->resolver->resolve($model, $field);
            } catch (Throwable) {
                continue;
            }
            $out[$field->name] = ['relation' => $rel, 'field' => $field];
        }

        return $out;
    }

    /**
     * @param array<string, array{relation:Relation, field:Field}> $relations
     */
    private function renderTypeImports(array $relations): string
    {
        if ($relations === []) {
            return '';
        }
        $seen = [];
        $lines = '';
        foreach ($relations as $info) {
            $target = $info['relation']->target;
            if (isset($seen[$target])) {
                continue;
            }
            $seen[$target] = true;
            $fqcn = $this->namespace . '\Model\\' . $target;
            $lines .= " * @phpstan-import-type {$target}RowScalar from \\{$fqcn}\n";
            $lines .= " * @phpstan-import-type {$target}WhereUnique from \\{$fqcn}\n";
        }

        return $lines;
    }

    private function rowScalarShape(Model $model): string
    {
        $parts = [];
        foreach ($model->scalarFields() as $f) {
            $parts[] = "{$f->columnName()}: " . TypeFormatter::phpType($f);
        }
        if ($parts === []) {
            return 'array{}';
        }

        return 'array{' . implode(', ', $parts) . '}';
    }

    /**
     * @param array<string, array{relation:Relation, field:Field}> $relations
     */
    private function rowShape(Model $model, array $relations): string
    {
        $parts = [];
        foreach ($model->scalarFields() as $f) {
            $parts[] = "{$f->columnName()}: " . TypeFormatter::phpType($f);
        }
        foreach ($relations as $name => $info) {
            $rel = $info['relation'];
            $relatedScalar = $rel->target . 'RowScalar';
            $type = $rel->isList() ? "list<{$relatedScalar}>" : "{$relatedScalar}|null";
            $parts[] = "{$name}?: " . $type;
        }

        return 'array{' . implode(', ', $parts) . '}';
    }

    /**
     * @param array<string, array{relation:Relation, field:Field}> $relations
     */
    private function insertInputShape(Model $model, array $relations): string
    {
        $parts = [];
        foreach ($model->scalarFields() as $f) {
            $optional = $this->isInsertOptional($f);
            $key = $f->columnName() . ($optional ? '?' : '');
            $parts[] = $key . ': ' . TypeFormatter::phpType($f);
        }
        foreach ($relations as $name => $info) {
            if (!$info['relation']->isManyToMany()) {
                continue;
            }
            $unique = $info['relation']->target . 'WhereUnique';
            $parts[] = "{$name}?: array{connect?: list<{$unique}>}";
        }

        return 'array{' . implode(', ', $parts) . '}';
    }

    /**
     * @param array<string, array{relation:Relation, field:Field}> $relations
     */
    private function updateInputShape(Model $model, array $relations): string
    {
        $parts = [];
        foreach ($model->scalarFields() as $f) {
            $parts[] = $f->columnName() . '?: ' . TypeFormatter::phpType($f);
        }
        foreach ($relations as $name => $info) {
            if (!$info['relation']->isManyToMany()) {
                continue;
            }
            $unique = $info['relation']->target . 'WhereUnique';
            $parts[] = "{$name}?: array{connect?: list<{$unique}>, disconnect?: list<{$unique}>, set?: list<{$unique}>}";
        }

        return 'array{' . implode(', ', $parts) . '}';
    }

    private function whereUniqueShape(Model $model): string
    {
        $seen = [];
        $parts = [];
        foreach ($model->uniqueFields() as $f) {
            $name = $f->columnName();
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $parts[] = $name . '?: ' . TypeFormatter::phpType($f);
        }

        $compositeCols = [];
        foreach ($model->compositePrimaryKey() ?? [] as $c) {
            $compositeCols[$c] = true;
        }
        foreach ($model->compositeUniqueGroups() as $group) {
            foreach ($group as $c) {
                $compositeCols[$c] = true;
            }
        }
        foreach (array_keys($compositeCols) as $col) {
            if (isset($seen[$col])) {
                continue;
            }
            $field = $model->field($col);
            $type = $field !== null ? TypeFormatter::phpType($field) : 'mixed';
            $parts[] = $col . '?: ' . $type;
            $seen[$col] = true;
        }

        if ($parts === []) {
            return 'array<string,mixed>';
        }

        return 'array{' . implode(', ', $parts) . '}';
    }

    /**
     * @param array<string, array{relation:Relation, field:Field}> $relations
     */
    private function includeShape(string $modelName, array $relations): string
    {
        if ($relations === []) {
            return 'array{}';
        }
        $parts = [];
        foreach ($relations as $name => $_info) {
            $sub = 'array{where?: array<string,mixed>, take?: int, skip?: int}';
            $parts[] = "{$name}?: bool|{$sub}";
        }

        return 'array{' . implode(', ', $parts) . '}';
    }

    /**
     * `select` only ever projects scalar columns — relations are handled by
     * `include` separately. Both map and list shorthand are accepted.
     */
    private function selectShape(Model $model): string
    {
        $mapParts = [];
        $literals = [];
        foreach ($model->scalarFields() as $f) {
            $col = $f->columnName();
            $mapParts[] = $col . '?: bool';
            $literals[] = var_export($col, true);
        }
        $mapForm = 'array{' . implode(', ', $mapParts) . '}';
        if ($literals === []) {
            return $mapForm;
        }
        $listForm = 'list<' . implode('|', $literals) . '>';

        return $mapForm . '|' . $listForm;
    }

    /**
     * @param array<string, array{relation:Relation, field:Field}> $relations
     */
    private function renderRelationsMethod(array $relations): string
    {
        if ($relations === []) {
            return '';
        }
        $entries = [];
        foreach ($relations as $name => $info) {
            $r = $info['relation'];
            $local = $this->phpArrayList($r->localFields);
            $foreign = $this->phpArrayList($r->foreignFields);
            if ($r->isManyToMany()) {
                $entries[] = sprintf(
                    "            '%s' => new Relation('%s', '%s', %s, %s, %s, %s, %s),",
                    $name,
                    $r->kind,
                    $r->target,
                    $local,
                    $foreign,
                    var_export($r->joinTable, true),
                    var_export($r->joinLocalColumn, true),
                    var_export($r->joinForeignColumn, true),
                );
            } else {
                $entries[] = sprintf(
                    "            '%s' => new Relation('%s', '%s', %s, %s),",
                    $name,
                    $r->kind,
                    $r->target,
                    $local,
                    $foreign,
                );
            }
        }
        $body = implode("\n", $entries);

        return <<<PHP
    /** @return array<string, Relation> */
    protected function relations(): array
    {
        return [
{$body}
        ];
    }


PHP;
    }

    private function isInsertOptional(Field $f): bool
    {
        if ($f->nullable) {
            return true;
        }
        if ($f->attribute('default') !== null) {
            return true;
        }

        return false;
    }

    /**
     * @param list<string> $list
     */
    private function phpArrayList(array $list): string
    {
        if ($list === []) {
            return '[]';
        }

        return '[' . implode(', ', array_map(static fn (string $v): string => var_export($v, true), $list)) . ']';
    }

    /**
     * @param array<string,string> $assoc
     */
    private function phpAssoc(array $assoc): string
    {
        if ($assoc === []) {
            return '[]';
        }
        $parts = [];
        foreach ($assoc as $k => $v) {
            $parts[] = var_export($k, true) . ' => ' . var_export($v, true);
        }

        return '[' . implode(', ', $parts) . ']';
    }
}
