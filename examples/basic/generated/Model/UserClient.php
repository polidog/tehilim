<?php

declare(strict_types=1);

namespace Example\Blog\Generated\Model;

use Polidog\Tehilim\Client\BaseModelClient;
use Polidog\Tehilim\Client\Relation;

/**
 * @phpstan-import-type PostRow from \Example\Blog\Generated\Model\PostClient
 * @phpstan-import-type PostWhereUnique from \Example\Blog\Generated\Model\PostClient
 * @phpstan-type UserRow array{id: int, email: string, name: string|null, createdAt: \DateTimeImmutable, posts?: list<PostRow>}
 * @phpstan-type UserInsertInput array{id?: int, email: string, name?: string|null, createdAt?: \DateTimeImmutable}
 * @phpstan-type UserUpdateInput array{id?: int, email?: string, name?: string|null, createdAt?: \DateTimeImmutable}
 * @phpstan-type UserWhereUnique array{id?: int, email?: string}
 * @phpstan-type UserWhereInput array<string,mixed>
 * @phpstan-type UserOrderBy array<string,'asc'|'desc'>|list<array<string,'asc'|'desc'>>
 * @phpstan-type UserInclude array{posts?: bool|array{where?: array<string,mixed>, take?: int, skip?: int}}
 * @phpstan-type UserSelect array{id?: bool, email?: bool, name?: bool, createdAt?: bool, posts?: bool}|list<'id'|'email'|'name'|'createdAt'|'posts'>
 */
final class UserClient extends BaseModelClient
{
    public const ?string PK = 'id';

    protected function table(): string
    {
        return 'User';
    }

    protected function primaryKey(): ?string
    {
        return 'id';
    }

    /** @return list<string> */
    protected function columns(): array
    {
        return ['id', 'email', 'name', 'createdAt'];
    }

    /** @return array<string,string> */
    protected function columnTypes(): array
    {
        return ['id' => 'int', 'email' => 'string', 'name' => 'string', 'createdAt' => 'DateTime'];
    }

    /** @return array<string, Relation> */
    protected function relations(): array
    {
        return [
            'posts' => new Relation('hasMany', 'Post', ['id'], ['authorId']),
        ];
    }


    /**
     * @param array{where: UserWhereUnique, include?: UserInclude, select?: UserSelect} $args
     * @return UserRow|null
     */
    public function findUnique(array $args): ?array
    {
        return $this->doFindUnique($args);
    }

    /**
     * @param array{where?: UserWhereInput, orderBy?: UserOrderBy, take?: int, skip?: int, include?: UserInclude, select?: UserSelect} $args
     * @return UserRow|null
     */
    public function findFirst(array $args = []): ?array
    {
        return $this->doFindFirst($args);
    }

    /**
     * @param array{where?: UserWhereInput, orderBy?: UserOrderBy, take?: int, skip?: int, include?: UserInclude, select?: UserSelect} $args
     * @return list<UserRow>
     */
    public function findMany(array $args = []): array
    {
        return $this->doFindMany($args);
    }

    /**
     * @param array{data: UserInsertInput} $args
     * @return UserRow
     */
    public function insert(array $args): array
    {
        return $this->doInsert($args);
    }

    /**
     * @param array{where: UserWhereUnique, data: UserUpdateInput} $args
     * @return UserRow
     */
    public function update(array $args): array
    {
        return $this->doUpdate($args);
    }

    /**
     * @param array{where: UserWhereUnique} $args
     * @return UserRow
     */
    public function delete(array $args): array
    {
        return $this->doDelete($args);
    }

    /**
     * @param array{where?: UserWhereInput} $args
     */
    public function count(array $args = []): int
    {
        return $this->doCount($args);
    }

    /**
     * @param array{where: UserWhereUnique, update: UserUpdateInput, insert: UserInsertInput} $args
     * @return UserRow
     */
    public function upsert(array $args): array
    {
        return $this->doUpsert($args);
    }

    /**
     * @param array{data: list<UserInsertInput>, skipDuplicates?: bool} $args
     * @return array{count: int}
     */
    public function insertMany(array $args): array
    {
        return $this->doInsertMany($args);
    }

    /**
     * @param array{where?: UserWhereInput, data: UserUpdateInput} $args
     * @return array{count: int}
     */
    public function updateMany(array $args): array
    {
        return $this->doUpdateMany($args);
    }

    /**
     * @param array{where?: UserWhereInput} $args
     * @return array{count: int}
     */
    public function deleteMany(array $args = []): array
    {
        return $this->doDeleteMany($args);
    }
}
