<?php

declare(strict_types=1);

namespace Example\Blog\Generated\Model;

use Polidog\Tehilim\Client\BaseModelClient;
use Polidog\Tehilim\Client\Relation;

/**
 * @phpstan-import-type PostRow from \Example\Blog\Generated\Model\PostClient
 * @phpstan-type UserRow array{id: int, email: string, name: string|null, createdAt: \DateTimeImmutable, posts?: list<PostRow>}
 * @phpstan-type UserCreateInput array{id?: int, email: string, name?: string|null, createdAt?: \DateTimeImmutable}
 * @phpstan-type UserUpdateInput array{id?: int, email?: string, name?: string|null, createdAt?: \DateTimeImmutable}
 * @phpstan-type UserWhereUnique array{id?: int, email?: string}
 * @phpstan-type UserWhereInput array<string,mixed>
 * @phpstan-type UserOrderBy array<string,'asc'|'desc'>|list<array<string,'asc'|'desc'>>
 * @phpstan-type UserInclude array{posts?: bool|array{where?: array<string,mixed>, take?: int, skip?: int}}
 * @phpstan-type UserSelect array{id?: bool, email?: bool, name?: bool, createdAt?: bool, posts?: bool}
 */
final class UserClient extends BaseModelClient
{
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
     * @param array{data: UserCreateInput} $args
     * @return UserRow
     */
    public function create(array $args): array
    {
        return $this->doCreate($args);
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
}
