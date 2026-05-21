<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Schema;

use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Schema\Ast\Invocation;
use Polidog\Tehilim\Schema\Parser;

final class ParserTest extends TestCase
{
    public function testParsesDatasourceAndGenerator(): void
    {
        $src = <<<'TXT'
datasource db {
  provider = "sqlite"
  url      = "sqlite::memory:"
}

generator client {
  output    = "./out"
  namespace = "Foo\\Bar"
}
TXT;
        $schema = Parser::parseString($src);
        self::assertCount(1, $schema->datasources);
        self::assertSame('sqlite', $schema->datasources[0]->provider());
        self::assertSame('sqlite::memory:', $schema->datasources[0]->url());
        self::assertSame('Foo\\Bar', $schema->generators[0]->namespace());
    }

    public function testParsesModelWithAttributes(): void
    {
        $src = <<<'TXT'
model User {
  id    Int     @id @default(autoincrement())
  email String  @unique
  name  String?
  age   Int?    @default(0)
}
TXT;
        $schema = Parser::parseString($src);
        $user = $schema->model('User');
        self::assertNotNull($user);

        $id = $user->field('id');
        self::assertNotNull($id);
        self::assertSame('Int', $id->type->name);
        self::assertTrue($id->hasAttribute('id'));
        $default = $id->attribute('default');
        self::assertNotNull($default);
        self::assertInstanceOf(Invocation::class, $default->args[0]);
        self::assertSame('autoincrement', $default->args[0]->name);

        $email = $user->field('email');
        self::assertNotNull($email);
        self::assertTrue($email->hasAttribute('unique'));

        $name = $user->field('name');
        self::assertNotNull($name);
        self::assertTrue($name->nullable);

        $age = $user->field('age');
        self::assertNotNull($age);
        self::assertTrue($age->nullable);
        $ageDefault = $age->attribute('default');
        self::assertNotNull($ageDefault);
        self::assertSame(0, $ageDefault->args[0]);
    }

    public function testParsesNamedArgs(): void
    {
        $src = <<<'TXT'
model Post {
  id       Int  @id
  authorId Int
  author   User @relation(fields: [authorId], references: [id])
}
TXT;
        $schema = Parser::parseString($src);
        $post = $schema->model('Post');
        self::assertNotNull($post);

        $author = $post->field('author');
        self::assertNotNull($author);
        $rel = $author->attribute('relation');
        self::assertNotNull($rel);
        self::assertSame(['authorId'], $rel->args['fields']);
        self::assertSame(['id'], $rel->args['references']);
    }
}
