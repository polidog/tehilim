<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Schema\Ast;

final class Schema
{
    /**
     * @param list<Model>      $models
     * @param list<Datasource> $datasources
     * @param list<Generator>  $generators
     */
    public function __construct(
        public readonly array $models = [],
        public readonly array $datasources = [],
        public readonly array $generators = [],
    ) {}

    public function model(string $name): ?Model
    {
        foreach ($this->models as $m) {
            if ($m->name === $name) {
                return $m;
            }
        }
        return null;
    }
}
