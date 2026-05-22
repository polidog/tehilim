<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Driver\SqliteDriver;
use Polidog\Tehilim\Migration\SchemaSync;
use Polidog\Tehilim\Schema\Ast\Model;
use Polidog\Tehilim\Schema\Introspector;
use Polidog\Tehilim\Schema\Parser;
use Polidog\Tehilim\Schema\SchemaPrinter;

/**
 * Round-trips a schema through SQLite: push it to a live DB, introspect it back,
 * and assert the recovered schema matches. Types are restricted to ones SQLite
 * can represent losslessly (Int/String/Float/Bytes) — DateTime/Json/Boolean/
 * BigInt collapse under SQLite's type affinity and are covered by the type-map
 * unit expectations instead.
 */
final class PullTest extends TestCase
{
    public function testRoundTripScalarsAndKeys(): void
    {
        $source = <<<'TXT'
datasource db { provider = "sqlite" url = "sqlite::memory:" }

model User {
  id    Int    @id @default(autoincrement())
  email String @unique
  name  String?
  score Float
  blob  Bytes?
}

model Enrollment {
  userId   Int
  courseId Int
  grade    String?
  @@id([userId, courseId])
}

model Membership {
  tenantId Int
  email    String
  @@unique([tenantId, email])
}
TXT;

        $schema = Parser::parseString($source);

        $pdo = new PDO('sqlite::memory:');
        $driver = new SqliteDriver($pdo);
        (new SchemaSync($driver, $schema))->push(drop: true);

        $pulled = (new Introspector($driver))->introspect($schema);

        // The User model: types, nullability, PK auto-increment, unique.
        $user = $this->model($pulled, 'User');
        self::assertSame(
            ['id', 'email', 'name', 'score', 'blob'],
            array_map(static fn ($f) => $f->name, $user->fields),
        );

        $id = $user->field('id');
        self::assertNotNull($id);
        self::assertSame('Int', $id->type->name);
        self::assertTrue($id->hasAttribute('id'));
        self::assertTrue($id->isGenerated(), 'id should be autoincrement');

        $email = $user->field('email');
        self::assertNotNull($email);
        self::assertSame('String', $email->type->name);
        self::assertFalse($email->nullable);
        self::assertTrue($email->hasAttribute('unique'));

        $name = $user->field('name');
        self::assertNotNull($name);
        self::assertTrue($name->nullable);

        $score = $user->field('score');
        self::assertNotNull($score);
        self::assertSame('Float', $score->type->name);

        $blob = $user->field('blob');
        self::assertNotNull($blob);
        self::assertSame('Bytes', $blob->type->name);
        self::assertTrue($blob->nullable);

        // Composite primary key recovered as @@id.
        $enrollment = $this->model($pulled, 'Enrollment');
        self::assertSame(['userId', 'courseId'], $enrollment->compositePrimaryKey());

        // Composite unique recovered as @@unique.
        $membership = $this->model($pulled, 'Membership');
        self::assertSame([['tenantId', 'email']], $membership->compositeUniqueGroups());
    }

    public function testPrintedPullReparsesEquivalently(): void
    {
        $source = <<<'TXT'
datasource db { provider = "sqlite" url = "sqlite::memory:" }

model Tag {
  id   Int    @id @default(autoincrement())
  slug String @unique
}
TXT;

        $schema = Parser::parseString($source);
        $driver = new SqliteDriver(new PDO('sqlite::memory:'));
        (new SchemaSync($driver, $schema))->push(drop: true);

        $pulled = (new Introspector($driver))->introspect($schema);
        $text = (new SchemaPrinter())->print($pulled);

        // The printed pull must be valid schema that parses back to the same
        // model shape.
        $reparsed = Parser::parseString($text);
        $tag = $this->model($reparsed, 'Tag');
        self::assertSame(['id', 'slug'], array_map(static fn ($f) => $f->name, $tag->fields));
        self::assertStringContainsString('datasource db', $text);
    }

    private function model(\Polidog\Tehilim\Schema\Ast\Schema $schema, string $name): Model
    {
        $model = $schema->model($name);
        self::assertNotNull($model, "model {$name} should exist");

        return $model;
    }
}
