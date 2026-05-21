<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Cache;

/**
 * In-process memoization keyed by a string. Scoped to the lifetime of the
 * owning BaseClient — typically one HTTP request — and flushed wholesale
 * whenever the client performs a write.
 *
 * Inspired by Relayer's CachingDatabase. There is no TTL, no cross-request
 * sharing, no LRU eviction; it is a plain identity map for read results.
 */
final class RequestCache
{
    /** @var array<string, mixed> */
    private array $entries = [];

    private int $hits = 0;
    private int $misses = 0;
    private int $writes = 0;

    /**
     * Probe + counter update in one call. Returns true when the key is
     * present (bumps hits) and false otherwise (bumps misses).
     */
    public function has(string $key): bool
    {
        if (array_key_exists($key, $this->entries)) {
            $this->hits++;
            return true;
        }
        $this->misses++;
        return false;
    }

    public function get(string $key): mixed
    {
        return $this->entries[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->entries[$key] = $value;
        $this->writes++;
    }

    public function flush(): void
    {
        $this->entries = [];
    }

    public function size(): int
    {
        return count($this->entries);
    }

    public function hits(): int
    {
        return $this->hits;
    }

    public function misses(): int
    {
        return $this->misses;
    }

    public function writes(): int
    {
        return $this->writes;
    }
}
