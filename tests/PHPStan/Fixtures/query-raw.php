<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\PHPStan\Fixtures;

use Polidog\Tehilim\Client\BaseClient;

use function PHPStan\Testing\assertType;

function typedShape(BaseClient $db): void
{
    $rows = $db->queryRaw(
        'SELECT u.id, COUNT(p.id) AS posts FROM "User" u LEFT JOIN "Post" p ON p."authorId" = u.id GROUP BY u.id',
        [],
        ['id' => 'int', 'posts' => 'int'],
    );
    assertType('list<array{id: int, posts: int}>', $rows);
}

function everyTag(BaseClient $db): void
{
    $rows = $db->queryRaw('SELECT 1', [], [
        'i' => 'int',
        'big' => 'BigInt',
        'f' => 'float',
        'b' => 'bool',
        's' => 'string',
        'raw' => 'bytes',
        'at' => 'DateTime',
        'doc' => 'json',
        'any' => 'mixed',
    ]);
    assertType(
        'list<array{i: int, big: int, f: float, b: bool, s: string, raw: string, at: DateTimeImmutable, doc: mixed, any: mixed}>',
        $rows,
    );
}

function nullableTags(BaseClient $db): void
{
    $rows = $db->queryRaw('SELECT 1', [], ['name' => '?string', 'score' => '?float', 'seen' => '?DateTime', 'doc' => '?json']);
    assertType('list<array{name: string|null, score: float|null, seen: DateTimeImmutable|null, doc: mixed}>', $rows);
}

function namedArgument(BaseClient $db): void
{
    $rows = $db->queryRaw(sql: 'SELECT COUNT(*) AS n FROM "User"', shape: ['n' => 'int']);
    assertType('list<array{n: int}>', $rows);
}

function noShape(BaseClient $db): void
{
    $rows = $db->queryRaw('SELECT * FROM "User"');
    assertType('list<array<string, mixed>>', $rows);
}

function emptyShape(BaseClient $db): void
{
    $rows = $db->queryRaw('SELECT * FROM "User"', [], []);
    assertType('list<array<string, mixed>>', $rows);
}

/** @param array<string,string> $shape */
function dynamicShape(BaseClient $db, array $shape): void
{
    $rows = $db->queryRaw('SELECT * FROM "User"', [], $shape);
    assertType('list<array<string, mixed>>', $rows);
}

function dynamicTag(BaseClient $db, string $tag): void
{
    $rows = $db->queryRaw('SELECT id FROM "User"', [], ['id' => $tag]);
    assertType('list<array<string, mixed>>', $rows);
}

function unknownTagFallsBack(BaseClient $db): void
{
    // The rule reports the typo; the type stays unnarrowed rather than lying.
    $rows = $db->queryRaw('SELECT id FROM "User"', [], ['id' => 'itn']);
    assertType('list<array<string, mixed>>', $rows);
}

function shapeDrivesAccess(BaseClient $db): void
{
    foreach ($db->queryRaw('SELECT id, email FROM "User"', [], ['id' => 'int', 'email' => 'string']) as $row) {
        assertType('int', $row['id']);
        assertType('string', $row['email']);
    }
}
