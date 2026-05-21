<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\PHPStan\Fixtures;

use Polidog\Tehilim\Tests\PHPStan\Fixtures\Generated\UserClient;

use function PHPStan\Testing\assertType;

function withoutSelect(UserClient $client): void
{
    $row = $client->findUnique(['where' => ['id' => 1]]);
    assertType('array{id: int, email: string, name: string|null, age: int|null}|null', $row);
}

function findUniqueNarrowed(UserClient $client): void
{
    $row = $client->findUnique([
        'where'  => ['id' => 1],
        'select' => ['email' => true, 'name' => true],
    ]);
    // primary key 'id' is auto-included to match runtime behavior
    assertType('array{email: string, name: string|null, id: int}|null', $row);
}

function findFirstNarrowed(UserClient $client): void
{
    $row = $client->findFirst([
        'select' => ['email' => true],
    ]);
    assertType('array{email: string, id: int}|null', $row);
}

function findManyNarrowed(UserClient $client): void
{
    $rows = $client->findMany([
        'select' => ['email' => true, 'age' => true],
    ]);
    assertType('list<array{email: string, age: int|null, id: int}>', $rows);
}

function selectIncludesPkExplicitly(UserClient $client): void
{
    // PK already in select: no duplicate in narrowed shape
    $row = $client->findUnique([
        'where'  => ['id' => 1],
        'select' => ['id' => true, 'email' => true],
    ]);
    assertType('array{id: int, email: string}|null', $row);
}

function allFalseFallsBack(UserClient $client): void
{
    // No true entries → don't narrow, return full row
    $row = $client->findUnique([
        'where'  => ['id' => 1],
        'select' => ['id' => false, 'email' => false],
    ]);
    assertType('array{id: int, email: string, name: string|null, age: int|null}|null', $row);
}

function listFormFindUnique(UserClient $client): void
{
    // Shorthand list form — same narrowing as map form
    $row = $client->findUnique([
        'where'  => ['id' => 1],
        'select' => ['email', 'name'],
    ]);
    assertType('array{email: string, name: string|null, id: int}|null', $row);
}

function listFormFindMany(UserClient $client): void
{
    $rows = $client->findMany(['select' => ['email', 'age']]);
    assertType('list<array{email: string, age: int|null, id: int}>', $rows);
}

function listFormIncludesPk(UserClient $client): void
{
    // PK explicit in list form: no duplicate
    $row = $client->findUnique([
        'where'  => ['id' => 1],
        'select' => ['id', 'email'],
    ]);
    assertType('array{id: int, email: string}|null', $row);
}
