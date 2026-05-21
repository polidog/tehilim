<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Schema\Ast;

/**
 * Represents a built-in call inside a schema attribute argument,
 * e.g. autoincrement(), now(), uuid(), cuid().
 */
final class Invocation
{
    /**
     * @param list<mixed> $args
     */
    public function __construct(
        public readonly string $name,
        public readonly array $args = [],
    ) {}
}
