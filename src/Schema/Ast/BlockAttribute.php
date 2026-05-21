<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Schema\Ast;

final class BlockAttribute
{
    /**
     * @param array<int|string,mixed> $args
     */
    public function __construct(
        public readonly string $name,
        public readonly array $args = [],
    ) {}
}
