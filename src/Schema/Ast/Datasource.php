<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Schema\Ast;

use RuntimeException;

final class Datasource
{
    /**
     * @param array<string,mixed> $options
     */
    public function __construct(
        public readonly string $name,
        public readonly array $options,
    ) {}

    public function provider(): string
    {
        $p = $this->options['provider'] ?? null;
        if (!is_string($p)) {
            throw new RuntimeException("datasource {$this->name}: 'provider' must be a string");
        }

        return $p;
    }

    public function url(): ?string
    {
        $u = $this->options['url'] ?? null;

        return is_string($u) ? $u : null;
    }
}
