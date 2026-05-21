<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Schema;

final class ParseException extends \RuntimeException
{
    public readonly int $sourceLine;

    public function __construct(string $message, int $line = 0)
    {
        $this->sourceLine = $line;
        parent::__construct($line > 0 ? "{$message} (line {$line})" : $message);
    }
}
