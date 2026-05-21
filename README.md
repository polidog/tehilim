# Tehilim

[English](README.md) · [日本語](README.ja.md)

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

PHP 8.5+, PDO with SQLite / MySQL / PostgreSQL.

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
  posts     Post[]
}

model Post {
  id        Int      @id @default(autoincrement())
  title     String
  body      String?
  published Boolean  @default(false)
  authorId  Int
  author    User     @relation(fields: [authorId], references: [id])
  createdAt DateTime @default(now())
}
```

## Workflow

```bash
vendor/bin/tehilim init                 # write a starter schema.tehilim
vendor/bin/tehilim generate             # (re)generate the typed client
vendor/bin/tehilim migrate dev --name init   # diff, write, and apply a migration
vendor/bin/tehilim migrate deploy       # apply unapplied migrations (CI/prod)
vendor/bin/tehilim migrate status       # list applied / pending
vendor/bin/tehilim migrate reset        # drop everything and re-apply (DEV ONLY)

# Prototyping shortcut — drop+recreate everything, no history:
vendor/bin/tehilim push
```

Migrations live under `./migrations/<YYYYmmddHHMMSSvvv>_<slug>/migration.sql`
with a `_snapshot.tehilim` alongside; applied migrations are recorded in
the `_tehilim_migrations` table.

## Connecting

You bring your own PDO — Tehilim picks the right driver from
`PDO::ATTR_DRIVER_NAME`. This lets you keep ownership of connection
attributes (charset, timezone, persistent flag, statement cache, etc.) and
makes it easy to share the connection with the rest of your stack.

```php
use App\Generated\TehilimClient;

$pdo = new PDO('sqlite:./dev.sqlite');
$db  = TehilimClient::fromPdo($pdo);

// Or, if you'd rather have Tehilim parse a URL for you:
// $db = TehilimClient::fromUrl('mysql://user:pass@host/db');
```

## Single-row CRUD

```php
$alice = $db->user->insert(['data' => [
    'email' => 'alice@example.com',
    'name'  => 'Alice',
]]);
// $alice: array{id:int, email:string, name:string|null, createdAt:\DateTimeImmutable, posts?: list<PostRow>}

$found = $db->user->findUnique(['where' => ['email' => 'alice@example.com']]);
if ($found !== null) {
    echo $found['name'];   // PHPStan knows: string|null
    echo $found['nope'];   // PHPStan error: not a key of array{...}
}

$db->user->update(['where' => ['id' => $alice['id']], 'data' => ['name' => 'Alice C']]);
$db->user->delete(['where' => ['id' => $alice['id']]]);
$count = $db->user->count(['where' => ['name' => ['startsWith' => 'A']]]);
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
    'orderBy' => ['createdAt' => 'desc'],
    'take'    => 20,
]);
```

## Relations: `include` and `select`

Relations declared in the schema become optional keys on each row. Ask for
them with `include`; Tehilim runs one batched `IN` query per relation and
merges the results.

```php
// hasMany: User.posts
$users = $db->user->findMany([
    'include' => ['posts' => ['where' => ['published' => true]]],
    'orderBy' => ['id' => 'asc'],
]);
foreach ($users as $u) {
    foreach ($u['posts'] ?? [] as $p) { echo "  {$p['title']}\n"; }
}

// belongsTo: Post.author
$posts = $db->post->findMany([
    'include' => ['author' => true],
]);

// Project a subset of columns (PK is always kept):
$slim = $db->user->findMany([
    'select' => ['id', 'email'],
]);
// Map form is equivalent — handy when you want to compute keys dynamically:
$slim = $db->user->findMany([
    'select' => ['id' => true, 'email' => true],
]);
// With the bundled PHPStan extension (see below), the return type is
// narrowed to list<array{id:int, email:string}> — PHPStan flags
// $row['name'] as missing.
```

## Many-to-many

Declare a list relation on both sides with no `@relation` and Tehilim
treats it as an implicit M2M, auto-creating an `_AToB`-style join table
(model names sorted alphabetically, columns `A` and `B` for the PKs).

```text
model Post {
  id    Int    @id @default(autoincrement())
  title String
  tags  Tag[]
}

model Tag {
  id    Int    @id @default(autoincrement())
  name  String @unique
  posts Post[]
}
```

Load with `include` (one batched `IN` query per side):

```php
$posts = $db->post->findMany(['include' => ['tags' => true]]);
$tags  = $db->tag->findMany([
    'include' => ['posts' => ['where' => ['published' => true]]],
]);
```

Mutate edges with `connect` / `disconnect` / `set` (callers supply the
foreign PK):

```php
$db->post->insert(['data' => [
    'title' => 'Hello',
    'tags'  => ['connect' => [['id' => 1], ['id' => 2]]],
]]);

$db->post->update([
    'where' => ['id' => 1],
    'data'  => ['tags' => ['disconnect' => [['id' => 2]]]],
]);

$db->post->update([
    'where' => ['id' => 1],
    'data'  => ['tags' => ['set' => [['id' => 3], ['id' => 4]]]],  // replace
]);
```

If you need a join table with extra columns (e.g. `assignedAt`), declare
it as a normal model with two `@relation` fields and a composite `@@id`
instead — Tehilim's explicit M2M.

## Composite keys

```text
model Enrollment {
  userId   Int
  courseId Int
  grade    String?

  @@id([userId, courseId])
}

model Member {
  id       Int    @id @default(autoincrement())
  tenantId Int
  email    String

  @@unique([tenantId, email])
}
```

Composite columns become optional keys on `WhereUnique` so `findUnique` /
`update` / `delete` keep working:

```php
$db->enrollment->findUnique(['where' => ['userId' => 1, 'courseId' => 100]]);
$db->member->findUnique(['where' => ['tenantId' => 1, 'email' => 'a@x']]);
```

## Bulk and upsert

```php
$db->user->insertMany([
    'data' => [
        ['email' => 'a@x'],
        ['email' => 'b@x'],
        ['email' => 'a@x'],   // duplicate
    ],
    'skipDuplicates' => true,  // SQLite OR IGNORE / MySQL IGNORE / pg ON CONFLICT
]);  // => ['count' => 2]

$db->user->updateMany([
    'where' => ['active' => false],
    'data'  => ['archived' => true],
]);  // => ['count' => N]

$db->user->deleteMany(['where' => ['archived' => true]]);

$db->user->upsert([
    'where'  => ['email' => 'a@x'],
    'insert' => ['email' => 'a@x', 'name' => 'A'],
    'update' => ['name'  => 'A'],
]);  // returns the resulting row
```

## Request-scoped cache

Per-call opt-in memoization for read calls. Chain `cached()` on the
model client and the next `findUnique` / `findFirst` / `findMany` /
`count` reads from (and stores into) the request-scoped cache; plain
calls always go to the DB. Any write (`insert` / `update` / `delete` /
`upsert` / `insertMany` / `updateMany` / `deleteMany`) **flushes the
entire cache before executing**, so a read-write-read pattern inside the
same request sees the update.

```php
$db = TehilimClient::fromPdo($pdo);

// Hot path — explicitly memoize this lookup
$me = $db->user->cached()->findUnique(['where' => ['id' => $uid]]);   // miss → store
$me = $db->user->cached()->findUnique(['where' => ['id' => $uid]]);   // hit

// Cold or sensitive read — plain call, never touches the cache
$fresh = $db->user->findUnique(['where' => ['id' => $uid]]);

$db->user->update(['where' => ['id' => $uid], 'data' => ['name' => 'X']]);
// cache is now empty; the next cached() read goes back to the DB

$db->cache()->hits();       // observability
$db->flushCache();          // drop manually if you change rows out-of-band
```

`cached()` returns a shallow clone of the model client with the same
generated type, so PHPStan-aware narrowing (`select`, `include`) keeps
working. You can also pin the clone to a local variable to reuse it
across several lookups:

```php
$users = $db->user->cached();
$alice = $users->findUnique(['where' => ['id' => 1]]);
$bob   = $users->findUnique(['where' => ['id' => 2]]);
```

The cache is scoped to a single `TehilimClient` instance — it's just an
in-memory array, no TTL, no cross-request sharing. Build a fresh client
per HTTP request (or whatever your unit of work is) and the cache lives
and dies with it. Inspired by Relayer's `CachingDatabase`.

Relations loaded via `include` are *not* cached automatically — only the
top-level call you marked with `cached()` is memoized. Cache keys are
derived from `serialize($args)`, so query args must be serializable.

## Transactions

`transaction()` runs the callback inside a transaction, commits on success,
rolls back on any throwable. Nested calls use `SAVEPOINT`, so a failed inner
block doesn't kill the outer transaction.

```php
use Polidog\Tehilim\Client\Rollback;

$alice = $db->transaction(function ($tx) {
    $u = $tx->user->insert(['data' => ['email' => 'a@x']]);
    $tx->post->insert(['data' => ['title' => 'Hello', 'authorId' => $u['id']]]);
    return $u;
});

// Throw Rollback to abort silently; the payload is returned to the caller.
$result = $db->transaction(function ($tx) {
    $tx->user->insert(['data' => ['email' => 'temp@x']]);
    throw new Rollback('discarded');
});  // $result === 'discarded'; nothing persisted

// Nested: inner failure does NOT abort the outer
$db->transaction(function ($tx) {
    $tx->user->insert(['data' => ['email' => 'outer@x']]);
    try {
        $tx->transaction(function ($t2) {
            $t2->user->insert(['data' => ['email' => 'inner@x']]);
            throw new \RuntimeException('inner fail');
        });
    } catch (\RuntimeException) {
        // inner SAVEPOINT rolled back; outer keeps going
    }
    $tx->user->insert(['data' => ['email' => 'after@x']]);
});
```

The closure parameter is typed as the concrete generated client, so
`$tx->user`, `$tx->post`, etc. are fully autocompleted and PHPStan-checked.

## Profiler hook

Tehilim wraps each operation with an optional callable that has the same
shape as Relayer's `Profiler::measure(string $collector, string $label,
callable $fn): mixed`, so a Relayer profiler plugs in directly:

```php
// Relayer integration — pass measure() as a first-class callable
$db = TehilimClient::fromPdo($pdo)
    ->withProfiler($relayer->profiler->measure(...));
```

Custom profilers are anything callable with that signature:

```php
$db->withProfiler(function (string $collector, string $label, callable $fn) {
    $start = hrtime(true);
    try {
        return $fn();
    } finally {
        error_log(sprintf('[%s/%s] %.2fms', $collector, $label, (hrtime(true) - $start) / 1e6));
    }
});

$db->withProfiler(null);  // clear
```

### Events emitted

| API call | collector | label |
|---|---|---|
| `findUnique` / `findFirst` / `findMany` | `tehilim.findUnique` etc. | `<Model>` |
| `insert` / `update` / `delete` / `count` | `tehilim.insert` etc. | `<Model>` |
| `upsert` / `insertMany` / `updateMany` / `deleteMany` | `tehilim.upsert` etc. | `<Model>` |

- `include` lookups recurse through the same hook, so M2M / hasMany
  loads nest naturally underneath their parent `findMany`.
- `upsert` nests over its internal `findFirst` + `insert` / `update`.
- Cache hits **skip** the profiler — only real database work is timed.
- Zero overhead when no profiler is registered (a single nullable
  property check).

## PHPStan extension

Tehilim ships a PHPStan extension at the package root (`extension.neon`)
that **narrows the return type of `findUnique` / `findFirst` / `findMany`**
when the caller passes a literal `select` argument:

```php
$row = $db->user->findUnique([
    'where'  => ['id' => 1],
    'select' => ['email', 'name'],
]);
// PHPStan sees: array{email: string, name: string|null, id: int}|null
// (PK is auto-included to match the runtime's behavior)

echo $row['email'];   // ✓
echo $row['name'];    // ✓
echo $row['age'];     // ✗ PHPStan error: not a key of the narrowed shape
```

### Setup

**Recommended — automatic via `phpstan/extension-installer`:**

```bash
composer require --dev phpstan/extension-installer
```

The installer reads `extra.phpstan.includes` from every installed
package's `composer.json` and wires up the extension. Nothing to add to
your own `phpstan.neon`.

**Manual — explicit include:**

```yaml
# phpstan.neon
includes:
    - vendor/polidog/tehilim/extension.neon
```

### How it works

- Generated model clients declare `public const ?string PK = 'id';`
  (or `null` for composite-PK models).
- The extension reads that constant via native reflection and adds the
  PK to the narrowed shape, matching the runtime's auto-include.
- The narrowing only fires for **literal** `select` arguments — both
  forms work:
  ```php
  ['select' => ['id', 'email']]                    // list shorthand
  ['select' => ['id' => true, 'email' => true]]    // map form
  ```
  Dynamic arrays (`$select` built at runtime, or a partial union of
  shapes) fall back to the unnarrowed `XxxRow` shape.
- Keys not present in the row shape are skipped silently. Relation
  names belong to `include`, not `select` — passing them here narrows
  to nothing useful, just as the runtime ignores them.

### Verifying it works

Add an `assertType` in any test and run PHPStan over it:

```php
use function PHPStan\Testing\assertType;

$row = $db->user->findUnique(['where' => ['id' => 1], 'select' => ['email']]);
assertType('array{email: string, id: int}|null', $row);
```

PHPStan's own test suite for the extension lives at
`tests/PHPStan/SelectNarrowingTest.php` if you want a reference.

## Status

v0.1 — usable for prototyping and small apps. Implemented:

- Single-row CRUD with array-shape PHPDoc types
- where operators + AND/OR/NOT
- `include` (hasMany / belongsTo / hasOne) + `select`
- `@@id` / `@@unique` composite keys
- Implicit many-to-many with auto-created `_AToB` join tables
- `insertMany` / `updateMany` / `deleteMany` / `upsert`
- `transaction()` with nested SAVEPOINTs and `Rollback`
- Opt-in request-scoped cache (auto-flush on writes)
- File-based migration history (`migrate dev` / `deploy` / `status` / `reset`)
- SQLite, MySQL/MariaDB, PostgreSQL drivers

Not yet: raw SQL escape hatch (`$queryRaw` / `$executeRaw`), JSON path
queries, full-text search, isolation-level control on transactions,
schema introspection from an existing DB.

## License

MIT
