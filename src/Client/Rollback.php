<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Client;

/**
 * Throw from inside a transaction callback to roll back without propagating
 * the exception to the caller. transaction() returns the optional payload.
 */
final class Rollback extends \RuntimeException
{
    public function __construct(public readonly mixed $payload = null)
    {
        parent::__construct('Tehilim transaction rolled back');
    }
}
