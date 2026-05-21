<?php

declare(strict_types=1);

namespace Example\Blog\Generated\Model;

use Polidog\Tehilim\Client\BaseModelClient;

/**
 * @phpstan-type UserRow array{id: int, email: string, name: string|null, createdAt: \DateTimeImmutable}
 * @phpstan-type UserCreateInput array{id?: int, email: string, name?: string|null, createdAt?: \DateTimeImmutable}
 * @phpstan-type UserUpdateInput array{id?: int, email?: string, name?: string|null, createdAt?: \DateTimeImmutable}
 * @phpstan-type UserWhereUnique array{id?: int, email?: string}
 * @phpstan-type UserWhereInput array<string,mixed>
 * @phpstan-type UserOrderBy array<string,'asc'|'desc'>|list<array<string,'asc'|'desc'>>
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

    /**
     * @param array{where: UserWhereUnique} $args
     * @return UserRow|null
     */
    public function findUnique(array $args): ?array
    {
        return $this->doFindUnique($args);
    }

    /**
     * @param array{where?: UserWhereInput, orderBy?: UserOrderBy, take?: int, skip?: int} $args
     * @return UserRow|null
     */
    public function findFirst(array $args = []): ?array
    {
        return $this->doFindFirst($args);
    }

    /**
     * @param array{where?: UserWhereInput, orderBy?: UserOrderBy, take?: int, skip?: int} $args
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
