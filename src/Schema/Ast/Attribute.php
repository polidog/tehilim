<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Schema\Ast;

final class Attribute
{
    /**
     * Positional args use int keys, named args use string keys.
     * Values are: string, int, float, bool, null, FunctionCall, or list<scalar|FunctionCall>.
     *
     * @param array<int|string,mixed> $args
     */
    public function __construct(
        public readonly string $name,
        public readonly array $args = [],
    ) {}
}
