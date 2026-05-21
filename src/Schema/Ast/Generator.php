<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Schema\Ast;

final class Generator
{
    /**
     * @param array<string,mixed> $options
     */
    public function __construct(
        public readonly string $name,
        public readonly array $options,
    ) {}

    public function output(): ?string
    {
        $o = $this->options['output'] ?? null;

        return is_string($o) ? $o : null;
    }

    public function namespace(): string
    {
        $n = $this->options['namespace'] ?? null;

        return is_string($n) ? $n : 'Tehilim\Generated';
    }
}
