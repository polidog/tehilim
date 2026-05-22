<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Schema\Parser;
use Polidog\Tehilim\Schema\SchemaPrinter;

final class SchemaPrinterTest extends TestCase
{
    public function testPrintsModelWithAttributes(): void
    {
        $schema = Parser::parseString(<<<'TXT'
model User {
  id    Int    @id @default(autoincrement())
  email String @unique
  name  String?
}
TXT);

        $printed = (new SchemaPrinter())->print($schema);

        self::assertStringContainsString('model User {', $printed);
        self::assertStringContainsString('id Int @id @default(autoincrement())', $printed);
        self::assertStringContainsString('email String @unique', $printed);
        self::assertStringContainsString('name String?', $printed);
    }

    public function testPrintsCompositeKeysAsBlockAttributes(): void
    {
        $schema = Parser::parseString(<<<'TXT'
model Enrollment {
  userId   Int
  courseId Int
  @@id([userId, courseId])
  @@unique([courseId, userId])
}
TXT);

        $printed = (new SchemaPrinter())->print($schema);

        self::assertStringContainsString('@@id([userId, courseId])', $printed);
        self::assertStringContainsString('@@unique([courseId, userId])', $printed);
    }

    public function testRoundTripsThroughParser(): void
    {
        $source = <<<'TXT'
datasource db {
  provider = "sqlite"
  url      = "sqlite::memory:"
}

generator client {
  output    = "./gen"
  namespace = "App\\Gen"
}

model User {
  id    Int    @id @default(autoincrement())
  email String @unique
  name  String?
}
TXT;

        $once = (new SchemaPrinter())->print(Parser::parseString($source));
        $twice = (new SchemaPrinter())->print(Parser::parseString($once));

        // Printing is idempotent: re-parsing printed output and printing again
        // yields the same text.
        self::assertSame($once, $twice);

        // And the datasource/generator survive the round-trip, including the
        // escaped backslash in the namespace.
        self::assertStringContainsString('provider = "sqlite"', $once);
        self::assertStringContainsString('namespace = "App\\\Gen"', $once);
    }
}
