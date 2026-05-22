<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Polidog\Tehilim\Driver\SqliteDriver;
use Polidog\Tehilim\Schema\Ast\Field;
use Polidog\Tehilim\Schema\Ast\Model;
use Polidog\Tehilim\Schema\Ast\Schema;
use Polidog\Tehilim\Schema\Introspector;
use Polidog\Tehilim\Schema\Parser;
use Polidog\Tehilim\Schema\SchemaPrinter;

/**
 * Relation inference during `pull`. tehilim's own push doesn't emit FK
 * constraints, so these tests build a FK-bearing database with raw SQL
 * (simulating a real external DB) and introspect it.
 */
final class PullRelationTest extends TestCase
{
    public function testInfersBelongsToHasManyHasOneAndManyToMany(): void
    {
        $pdo = $this->pdo([
            'CREATE TABLE "User" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "email" TEXT NOT NULL UNIQUE)',
            'CREATE TABLE "Post" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "title" TEXT NOT NULL, "authorId" INTEGER NOT NULL REFERENCES "User"("id"))',
            'CREATE TABLE "Profile" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "userId" INTEGER NOT NULL UNIQUE REFERENCES "User"("id"))',
            'CREATE TABLE "Tag" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "name" TEXT NOT NULL UNIQUE)',
            'CREATE TABLE "_PostToTag" ("A" INTEGER NOT NULL REFERENCES "Post"("id"), "B" INTEGER NOT NULL REFERENCES "Tag"("id"), PRIMARY KEY ("A", "B"))',
        ]);

        $schema = (new Introspector(new SqliteDriver($pdo)))->introspect();

        // The join table is folded away, not emitted as a model.
        self::assertSame(
            ['Post', 'Profile', 'Tag', 'User'],
            array_map(static fn ($m) => $m->name, $schema->models),
        );

        // Post.authorId -> belongsTo User, with @relation(fields, references).
        $post = $this->model($schema, 'Post');
        $belongs = $this->relationField($post, 'User', list: false);
        self::assertNotNull($belongs, 'Post should have a belongsTo User');
        self::assertFalse($belongs->nullable, 'belongsTo follows the NOT NULL FK column');
        $rel = $belongs->attribute('relation');
        self::assertNotNull($rel);
        self::assertSame(['authorId'], $rel->args['fields']);
        self::assertSame(['id'], $rel->args['references']);

        // Post also has a many-to-many list to Tag (no @relation on the field).
        $m2m = $this->relationField($post, 'Tag', list: true);
        self::assertNotNull($m2m, 'Post should have a M2M list to Tag');
        self::assertNull($m2m->attribute('relation'));

        // User.posts -> hasMany Post (authorId not unique); User.profile -> hasOne.
        $user = $this->model($schema, 'User');
        self::assertNotNull($this->relationField($user, 'Post', list: true), 'User should have hasMany Post');
        $profileRel = $this->relationField($user, 'Profile', list: false);
        self::assertNotNull($profileRel, 'User should have hasOne Profile');
        self::assertNull($profileRel->attribute('relation'), 'inverse side carries no @relation');

        // Tag has the M2M back to Post.
        $tag = $this->model($schema, 'Tag');
        self::assertNotNull($this->relationField($tag, 'Post', list: true), 'Tag should have M2M to Post');
    }

    public function testPrintedRelationsReparse(): void
    {
        $pdo = $this->pdo([
            'CREATE TABLE "User" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "email" TEXT NOT NULL UNIQUE)',
            'CREATE TABLE "Post" ("id" INTEGER PRIMARY KEY AUTOINCREMENT, "authorId" INTEGER NOT NULL REFERENCES "User"("id"))',
        ]);

        $schema = (new Introspector(new SqliteDriver($pdo)))->introspect();
        $text = (new SchemaPrinter())->print($schema);

        self::assertStringContainsString('@relation(fields: [authorId], references: [id])', $text);

        // The printed relation parses back to the same shape.
        $reparsed = Parser::parseString($text);
        $post = $this->model($reparsed, 'Post');
        $belongs = $this->relationField($post, 'User', list: false);
        self::assertNotNull($belongs);
        $rel = $belongs->attribute('relation');
        self::assertNotNull($rel);
        self::assertSame(['authorId'], $rel->args['fields']);
    }

    /**
     * @param list<string> $ddl
     */
    private function pdo(array $ddl): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ($ddl as $sql) {
            $pdo->prepare($sql)->execute();
        }

        return $pdo;
    }

    private function relationField(Model $model, string $targetType, bool $list): ?Field
    {
        foreach ($model->fields as $f) {
            if (!$f->type->isScalar() && $f->type->name === $targetType && $f->list === $list) {
                return $f;
            }
        }

        return null;
    }

    private function model(Schema $schema, string $name): Model
    {
        $model = $schema->model($name);
        self::assertNotNull($model, "model {$name} should exist");

        return $model;
    }
}
