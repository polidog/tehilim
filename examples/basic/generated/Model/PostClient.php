<?php

declare(strict_types=1);

namespace Example\Blog\Generated\Model;

use Polidog\Tehilim\Client\BaseModelClient;

/**
 * @phpstan-type PostRow array{id: int, title: string, body: string|null, published: bool, authorId: int, createdAt: \DateTimeImmutable}
 * @phpstan-type PostCreateInput array{id?: int, title: string, body?: string|null, published?: bool, authorId: int, createdAt?: \DateTimeImmutable}
 * @phpstan-type PostUpdateInput array{id?: int, title?: string, body?: string|null, published?: bool, authorId?: int, createdAt?: \DateTimeImmutable}
 * @phpstan-type PostWhereUnique array{id?: int}
 * @phpstan-type PostWhereInput array<string,mixed>
 * @phpstan-type PostOrderBy array<string,'asc'|'desc'>|list<array<string,'asc'|'desc'>>
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

    /**
     * @param array{where: PostWhereUnique} $args
     * @return PostRow|null
     */
    public function findUnique(array $args): ?array
    {
        return $this->doFindUnique($args);
    }

    /**
     * @param array{where?: PostWhereInput, orderBy?: PostOrderBy, take?: int, skip?: int} $args
     * @return PostRow|null
     */
    public function findFirst(array $args = []): ?array
    {
        return $this->doFindFirst($args);
    }

    /**
     * @param array{where?: PostWhereInput, orderBy?: PostOrderBy, take?: int, skip?: int} $args
     * @return list<PostRow>
     */
    public function findMany(array $args = []): array
    {
        return $this->doFindMany($args);
    }

    /**
     * @param array{data: PostCreateInput} $args
     * @return PostRow
     */
    public function create(array $args): array
    {
        return $this->doCreate($args);
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
}
