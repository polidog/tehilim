# Tehilim

Prisma-style, schema-first database toolkit for PHP — without class mapping.

You declare your data model in a single schema file. Tehilim generates a
typed query client. There are no entity classes; rows come back as plain
associative arrays, and **PHPStan / Psalm / IDEs see the exact shape** of
every field thanks to generated `@phpstan-type` definitions.

```text
schema.tehilim ──► tehilim generate ──► Generated/TehilimClient.php
                                        Generated/Model/UserClient.php
                                        Generated/Model/PostClient.php
```

## Why arrays, not objects?

Object-relational mapping forces the database shape into a class hierarchy
that drifts as the schema evolves. Tehilim takes a different route: data is
data (an associative array), the **types** are first-class via PHPDoc array
shapes, and the static analyser does the work of catching shape mismatches.

## Install

```bash
composer require polidog/tehilim
```

PHP 8.3+, PDO with SQLite / MySQL / PostgreSQL.

## Schema

`schema.tehilim`:

```text
datasource db {
  provider = "sqlite"
  url      = "sqlite:./dev.sqlite"
}

generator client {
  output    = "./src/Generated"
  namespace = "App\\Generated"
}

model User {
  id        Int      @id @default(autoincrement())
  email     String   @unique
  name      String?
  createdAt DateTime @default(now())
}

model Post {
  id        Int      @id @default(autoincrement())
  title     String
  body      String?
  published Boolean  @default(false)
  authorId  Int
  createdAt DateTime @default(now())
}
```

## Workflow

```bash
vendor/bin/tehilim init       # write a starter schema.tehilim
vendor/bin/tehilim push       # create tables in the configured DB (destructive in v0)
vendor/bin/tehilim generate   # generate the typed client
```

## Using the generated client

```php
use App\Generated\TehilimClient;
use Polidog\Tehilim\Config;

$db = TehilimClient::connect(Config::fromUrl('sqlite:./dev.sqlite'));

$alice = $db->user->create(['data' => [
    'email' => 'alice@example.com',
    'name'  => 'Alice',
]]);
// $alice: array{id:int, email:string, name:string|null, createdAt:\DateTimeImmutable}

$found = $db->user->findUnique(['where' => ['email' => 'alice@example.com']]);
if ($found !== null) {
    echo $found['name'];          // PHPStan knows: string|null
    echo $found['nope'];          // PHPStan error: not a key of array{...}
}

$published = $db->post->findMany([
    'where'   => ['published' => true, 'authorId' => $alice['id']],
    'orderBy' => ['createdAt' => 'desc'],
    'take'    => 20,
]);
```

## Where operators

`equals`, `not`, `in`, `notIn`, `lt`, `lte`, `gt`, `gte`, `contains`,
`startsWith`, `endsWith`. Top-level `AND` / `OR` / `NOT` group sub-clauses.

```php
$db->post->findMany([
    'where' => [
        'OR' => [
            ['title' => ['contains' => 'PHP']],
            ['body'  => ['contains' => 'PHP']],
        ],
        'published' => true,
    ],
]);
```

## Status

v0 — happy path. Things still to come: relation includes / joins, migration
history (only destructive `push` today), composite unique keys, transactions
on the per-model client, batch operations.

## License

MIT
