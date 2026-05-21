<?php

declare(strict_types=1);

namespace Example\Blog\Generated\Model;

use Polidog\Tehilim\Client\BaseModelClient;
use Polidog\Tehilim\Client\Relation;

/**
 * @phpstan-import-type UserRow from \Example\Blog\Generated\Model\UserClient
 * @phpstan-import-type UserWhereUnique from \Example\Blog\Generated\Model\UserClient
 * @phpstan-type PostRow array{id: int, title: string, body: string|null, published: bool, authorId: int, createdAt: \DateTimeImmutable, author?: UserRow|null}
 * @phpstan-type PostInsertInput array{id?: int, title: string, body?: string|null, published?: bool, authorId: int, createdAt?: \DateTimeImmutable}
 * @phpstan-type PostUpdateInput array{id?: int, title?: string, body?: string|null, published?: bool, authorId?: int, createdAt?: \DateTimeImmutable}
 * @phpstan-type PostWhereUnique array{id?: int}
 * @phpstan-type PostWhereInput array<string,mixed>
 * @phpstan-type PostOrderBy array<string,'asc'|'desc'>|list<array<string,'asc'|'desc'>>
 * @phpstan-type PostInclude array{author?: bool|array{where?: array<string,mixed>, take?: int, skip?: int}}
 * @phpstan-type PostSelect array{id?: bool, title?: bool, body?: bool, published?: bool, authorId?: bool, createdAt?: bool, author?: bool}
 */
final class PostClient extends BaseModelClient
{
    protected function table(): string
    {
        return 'Post';
    }

    protected function primaryKey(): ?string
    {
        return 'id';
    }

    /** @return list<string> */
    protected function columns(): array
    {
        return ['id', 'title', 'body', 'published', 'authorId', 'createdAt'];
    }

    /** @return array<string,string> */
    protected function columnTypes(): array
    {
        return ['id' => 'int', 'title' => 'string', 'body' => 'string', 'published' => 'bool', 'authorId' => 'int', 'createdAt' => 'DateTime'];
    }

    /** @return array<string, Relation> */
    protected function relations(): array
    {
        return [
            'author' => new Relation('belongsTo', 'User', ['authorId'], ['id']),
        ];
    }


    /**
     * @param array{where: PostWhereUnique, include?: PostInclude, select?: PostSelect} $args
     * @return PostRow|null
     */
    public function findUnique(array $args): ?array
    {
        return $this->doFindUnique($args);
    }

    /**
     * @param array{where?: PostWhereInput, orderBy?: PostOrderBy, take?: int, skip?: int, include?: PostInclude, select?: PostSelect} $args
     * @return PostRow|null
     */
    public function findFirst(array $args = []): ?array
    {
        return $this->doFindFirst($args);
    }

    /**
     * @param array{where?: PostWhereInput, orderBy?: PostOrderBy, take?: int, skip?: int, include?: PostInclude, select?: PostSelect} $args
     * @return list<PostRow>
     */
    public function findMany(array $args = []): array
    {
        return $this->doFindMany($args);
    }

    /**
     * @param array{data: PostInsertInput} $args
     * @return PostRow
     */
    public function insert(array $args): array
    {
        return $this->doInsert($args);
    }

    /**
     * @param array{where: PostWhereUnique, data: PostUpdateInput} $args
     * @return PostRow
     */
    public function update(array $args): array
    {
        return $this->doUpdate($args);
    }

    /**
     * @param array{where: PostWhereUnique} $args
     * @return PostRow
     */
    public function delete(array $args): array
    {
        return $this->doDelete($args);
    }

    /**
     * @param array{where?: PostWhereInput} $args
     */
    public function count(array $args = []): int
    {
        return $this->doCount($args);
    }

    /**
     * @param array{where: PostWhereUnique, update: PostUpdateInput, insert: PostInsertInput} $args
     * @return PostRow
     */
    public function upsert(array $args): array
    {
        return $this->doUpsert($args);
    }

    /**
     * @param array{data: list<PostInsertInput>, skipDuplicates?: bool} $args
     * @return array{count: int}
     */
    public function insertMany(array $args): array
    {
        return $this->doInsertMany($args);
    }

    /**
     * @param array{where?: PostWhereInput, data: PostUpdateInput} $args
     * @return array{count: int}
     */
    public function updateMany(array $args): array
    {
        return $this->doUpdateMany($args);
    }

    /**
     * @param array{where?: PostWhereInput} $args
     * @return array{count: int}
     */
    public function deleteMany(array $args = []): array
    {
        return $this->doDeleteMany($args);
    }
}
