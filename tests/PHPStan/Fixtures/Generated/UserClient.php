<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Tests\PHPStan\Fixtures\Generated;

use Polidog\Tehilim\Client\BaseModelClient;

/**
 * Mirrors what the code generator emits, kept minimal so the PHPStan
 * extension test has a stable analysis target.
 *
 * @phpstan-type UserRow array{id: int, email: string, name: string|null, age: int|null}
 * @phpstan-type UserWhereUnique array{id?: int, email?: string}
 * @phpstan-type UserWhereInput array<string,mixed>
 * @phpstan-type UserSelect array{id?: bool, email?: bool, name?: bool, age?: bool}|list<'id'|'email'|'name'|'age'>
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
        return ['id', 'email', 'name', 'age'];
    }

    /** @return array<string,string> */
    protected function columnTypes(): array
    {
        return ['id' => 'int', 'email' => 'string', 'name' => 'string', 'age' => 'int'];
    }

    /**
     * @param array{where: UserWhereUnique, select?: UserSelect} $args
     * @return UserRow|null
     */
    public function findUnique(array $args): ?array
    {
        return $this->doFindUnique($args);
    }

    /**
     * @param array{where?: UserWhereInput, select?: UserSelect, take?: int, skip?: int} $args
     * @return UserRow|null
     */
    public function findFirst(array $args = []): ?array
    {
        return $this->doFindFirst($args);
    }

    /**
     * @param array{where?: UserWhereInput, select?: UserSelect, take?: int, skip?: int} $args
     * @return list<UserRow>
     */
    public function findMany(array $args = []): array
    {
        return $this->doFindMany($args);
    }
}
