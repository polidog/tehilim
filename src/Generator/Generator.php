<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Generator;

use Polidog\Tehilim\Client\Relation;
use Polidog\Tehilim\Schema\Ast\Field;
use Polidog\Tehilim\Schema\Ast\Model;
use Polidog\Tehilim\Schema\Ast\Schema;
use Polidog\Tehilim\Schema\RelationResolver;

final class Generator
{
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
        $modelDir = $this->outputDir . '/Model';
        if (!is_dir($modelDir) && !mkdir($modelDir, 0755, true) && !is_dir($modelDir)) {
            throw new \RuntimeException("Cannot create directory: {$modelDir}");
        }

        foreach ($this->schema->models as $model) {
            $path = $modelDir . '/' . $model->name . 'Client.php';
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
        $uses = "use PDO;\nuse Polidog\\Tehilim\\Client\\BaseClient;\nuse Polidog\\Tehilim\\Config;\nuse Polidog\\Tehilim\\Driver\\Driver;\nuse Polidog\\Tehilim\\Driver\\Drivers;\n";

        foreach ($models as $m) {
            $cls = $m->name . 'Client';
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
     * @return T|mixed
     */
    public function transaction(callable \$fn): mixed
    {
        return parent::transaction(\$fn);
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
        $rowShape = $this->rowShape($model, $relations);
        $createShape = $this->createInputShape($model);
        $updateShape = $this->updateInputShape($model);
        $whereUniqueShape = $this->whereUniqueShape($model);
        $includeShape = $this->includeShape($name, $relations);
        $selectShape = $this->selectShape($model, $relations);

        $columnsArray = $this->phpArrayList(array_map(
            static fn (Field $f): string => $f->columnName(),
            $model->scalarFields(),
        ));

        $columnTypesArray = $this->phpAssoc(array_combine(
            array_map(static fn (Field $f): string => $f->columnName(), $model->scalarFields()),
            array_map(static fn (Field $f): string => TypeFormatter::columnType($f), $model->scalarFields()),
        ));

        $primaryConst = $pkName === null ? 'null' : var_export($pkName, true);

        $relationsMethod = $this->renderRelationsMethod($relations);

        $extraImports = $relations === [] ? '' : "\nuse Polidog\\Tehilim\\Client\\Relation;";

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$this->namespace}\\Model;

use Polidog\\Tehilim\\Client\\BaseModelClient;{$extraImports}

/**
{$imports} * @phpstan-type {$name}Row {$rowShape}
 * @phpstan-type {$name}CreateInput {$createShape}
 * @phpstan-type {$name}UpdateInput {$updateShape}
 * @phpstan-type {$name}WhereUnique {$whereUniqueShape}
 * @phpstan-type {$name}WhereInput array<string,mixed>
 * @phpstan-type {$name}OrderBy array<string,'asc'|'desc'>|list<array<string,'asc'|'desc'>>
 * @phpstan-type {$name}Include {$includeShape}
 * @phpstan-type {$name}Select {$selectShape}
 */
final class {$name}Client extends BaseModelClient
{
    protected function table(): string
    {
        return '{$table}';
    }

    protected function primaryKey(): ?string
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
        return \$this->doFindUnique(\$args);
    }

    /**
     * @param array{where?: {$name}WhereInput, orderBy?: {$name}OrderBy, take?: int, skip?: int, include?: {$name}Include, select?: {$name}Select} \$args
     * @return {$name}Row|null
     */
    public function findFirst(array \$args = []): ?array
    {
        return \$this->doFindFirst(\$args);
    }

    /**
     * @param array{where?: {$name}WhereInput, orderBy?: {$name}OrderBy, take?: int, skip?: int, include?: {$name}Include, select?: {$name}Select} \$args
     * @return list<{$name}Row>
     */
    public function findMany(array \$args = []): array
    {
        return \$this->doFindMany(\$args);
    }

    /**
     * @param array{data: {$name}CreateInput} \$args
     * @return {$name}Row
     */
    public function create(array \$args): array
    {
        return \$this->doCreate(\$args);
    }

    /**
     * @param array{where: {$name}WhereUnique, data: {$name}UpdateInput} \$args
     * @return {$name}Row
     */
    public function update(array \$args): array
    {
        return \$this->doUpdate(\$args);
    }

    /**
     * @param array{where: {$name}WhereUnique} \$args
     * @return {$name}Row
     */
    public function delete(array \$args): array
    {
        return \$this->doDelete(\$args);
    }

    /**
     * @param array{where?: {$name}WhereInput} \$args
     */
    public function count(array \$args = []): int
    {
        return \$this->doCount(\$args);
    }

    /**
     * @param array{where: {$name}WhereUnique, update: {$name}UpdateInput, create: {$name}CreateInput} \$args
     * @return {$name}Row
     */
    public function upsert(array \$args): array
    {
        return \$this->doUpsert(\$args);
    }

    /**
     * @param array{data: list<{$name}CreateInput>, skipDuplicates?: bool} \$args
     * @return array{count: int}
     */
    public function createMany(array \$args): array
    {
        return \$this->doCreateMany(\$args);
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
            } catch (\Throwable) {
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
            $fqcn = $this->namespace . '\\Model\\' . $target . 'Client';
            $lines .= " * @phpstan-import-type {$target}Row from \\{$fqcn}\n";
        }
        return $lines;
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
            $relatedRow = $rel->target . 'Row';
            $type = $rel->isList() ? "list<{$relatedRow}>" : "{$relatedRow}|null";
            $parts[] = "{$name}?: " . $type;
        }
        return 'array{' . implode(', ', $parts) . '}';
    }

    private function createInputShape(Model $model): string
    {
        $parts = [];
        foreach ($model->scalarFields() as $f) {
            $optional = $this->isCreateOptional($f);
            $key = $f->columnName() . ($optional ? '?' : '');
            $parts[] = $key . ': ' . TypeFormatter::phpType($f);
        }
        return 'array{' . implode(', ', $parts) . '}';
    }

    private function updateInputShape(Model $model): string
    {
        $parts = [];
        foreach ($model->scalarFields() as $f) {
            $parts[] = $f->columnName() . '?: ' . TypeFormatter::phpType($f);
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
     * @param array<string, array{relation:Relation, field:Field}> $relations
     */
    private function selectShape(Model $model, array $relations): string
    {
        $parts = [];
        foreach ($model->scalarFields() as $f) {
            $parts[] = $f->columnName() . '?: bool';
        }
        foreach ($relations as $name => $_info) {
            $parts[] = $name . '?: bool';
        }
        return 'array{' . implode(', ', $parts) . '}';
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
            $entries[] = sprintf(
                "            '%s' => new Relation('%s', '%s', %s, %s),",
                $name,
                $r->kind,
                $r->target,
                $local,
                $foreign,
            );
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

    private function isCreateOptional(Field $f): bool
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
