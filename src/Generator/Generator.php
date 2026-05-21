<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Generator;

use Polidog\Tehilim\Schema\Ast\Field;
use Polidog\Tehilim\Schema\Ast\Model;
use Polidog\Tehilim\Schema\Ast\Schema;

final class Generator
{
    public function __construct(
        private readonly Schema $schema,
        private readonly string $outputDir,
        private readonly string $namespace,
        private readonly string $clientClass = 'TehilimClient',
    ) {}

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
        $uses = "use Polidog\\Tehilim\\Client\\BaseClient;\nuse Polidog\\Tehilim\\Config;\nuse Polidog\\Tehilim\\Driver\\Driver;\n";

        foreach ($models as $m) {
            $cls = $m->name . 'Client';
            $prop = lcfirst($m->name);
            $uses .= "use {$this->namespace}\\Model\\{$cls};\n";
            $properties .= "    public readonly {$cls} \${$prop};\n";
            $assigns .= "        \$this->{$prop} = new {$cls}(\$driver);\n";
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

    public static function connect(Config \$config): self
    {
        return new self(\$config->driver());
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

        $rowShape = $this->rowShape($model);
        $createShape = $this->createInputShape($model);
        $updateShape = $this->updateInputShape($model);
        $whereUniqueShape = $this->whereUniqueShape($model);

        $columnsArray = $this->phpArrayList(array_map(
            static fn (Field $f): string => $f->columnName(),
            $model->scalarFields(),
        ));

        $columnTypesArray = $this->phpAssoc(array_combine(
            array_map(static fn (Field $f): string => $f->columnName(), $model->scalarFields()),
            array_map(static fn (Field $f): string => TypeFormatter::columnType($f), $model->scalarFields()),
        ));

        $primaryConst = $pkName === null ? 'null' : var_export($pkName, true);

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$this->namespace}\\Model;

use Polidog\\Tehilim\\Client\\BaseModelClient;

/**
 * @phpstan-type {$name}Row {$rowShape}
 * @phpstan-type {$name}CreateInput {$createShape}
 * @phpstan-type {$name}UpdateInput {$updateShape}
 * @phpstan-type {$name}WhereUnique {$whereUniqueShape}
 * @phpstan-type {$name}WhereInput array<string,mixed>
 * @phpstan-type {$name}OrderBy array<string,'asc'|'desc'>|list<array<string,'asc'|'desc'>>
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

    /**
     * @param array{where: {$name}WhereUnique} \$args
     * @return {$name}Row|null
     */
    public function findUnique(array \$args): ?array
    {
        return \$this->doFindUnique(\$args);
    }

    /**
     * @param array{where?: {$name}WhereInput, orderBy?: {$name}OrderBy, take?: int, skip?: int} \$args
     * @return {$name}Row|null
     */
    public function findFirst(array \$args = []): ?array
    {
        return \$this->doFindFirst(\$args);
    }

    /**
     * @param array{where?: {$name}WhereInput, orderBy?: {$name}OrderBy, take?: int, skip?: int} \$args
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
}

PHP;
    }

    private function rowShape(Model $model): string
    {
        $parts = [];
        foreach ($model->scalarFields() as $f) {
            $parts[] = "{$f->columnName()}: " . TypeFormatter::phpType($f);
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
        $parts = [];
        foreach ($model->uniqueFields() as $f) {
            $parts[] = $f->columnName() . '?: ' . TypeFormatter::phpType($f);
        }
        if ($parts === []) {
            return 'array<string,mixed>';
        }
        return 'array{' . implode(', ', $parts) . '}';
    }

    private function isCreateOptional(Field $f): bool
    {
        if ($f->nullable) {
            return true;
        }
        $default = $f->attribute('default');
        if ($default !== null) {
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
        return "[" . implode(', ', array_map(static fn (string $v): string => var_export($v, true), $list)) . "]";
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
