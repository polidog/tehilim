<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\PHPStan\Fixtures;

use Polidog\Tehilim\Client\BaseClient;

function reported(BaseClient $db): void
{
    $db->queryRaw('SELECT id, email FROM "User"', [], ['id' => 'itn', 'email' => 'string']);
    $db->queryRaw('SELECT id FROM "User"', shape: ['id' => '?integer']);
    $db->queryRaw('SELECT id FROM "User"', [], ['id' => 'int', 'x' => 'nope', 'y' => 'Nope']);
}

/** @param array<string,string> $shape */
function notReported(BaseClient $db, array $shape, string $tag): void
{
    $db->queryRaw('SELECT id FROM "User"', [], ['id' => 'int', 'name' => '?string', 'at' => 'DateTime']);
    $db->queryRaw('SELECT id FROM "User"');
    $db->queryRaw('SELECT id FROM "User"', [], []);
    $db->queryRaw('SELECT id FROM "User"', [], $shape);
    $db->queryRaw('SELECT id FROM "User"', [], ['id' => $tag]);
}

final class NotAClient
{
    /** @param array<string,string> $shape */
    public function queryRaw(string $sql, array $params = [], array $shape = []): void
    {
    }
}

function unrelatedQueryRaw(NotAClient $other): void
{
    $other->queryRaw('x', [], ['id' => 'itn']);
}
