<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Migration;

/**
 * Filesystem layout for migrations:
 *
 *   <baseDir>/
 *     _snapshot.tehilim                <- copy of schema at last migration
 *     <id>/
 *       migration.sql                  <- DDL to apply
 *
 * Migration id format: YYYYmmddHHMMSS_slug
 */
final class MigrationStore
{
    public function __construct(public readonly string $baseDir) {}

    public function ensureDir(): void
    {
        if (!is_dir($this->baseDir) && !mkdir($this->baseDir, 0755, true) && !is_dir($this->baseDir)) {
            throw new \RuntimeException("Cannot create migrations dir: {$this->baseDir}");
        }
    }

    public function snapshotPath(): string
    {
        return $this->baseDir . '/_snapshot.tehilim';
    }

    public function snapshotSchema(): string
    {
        $p = $this->snapshotPath();
        if (!is_file($p)) {
            return '';
        }
        $src = file_get_contents($p);
        return $src === false ? '' : $src;
    }

    public function writeSnapshot(string $schemaSource): void
    {
        $this->ensureDir();
        file_put_contents($this->snapshotPath(), $schemaSource);
    }

    /** @return list<string> migration ids in lexical order */
    public function listMigrations(): array
    {
        if (!is_dir($this->baseDir)) {
            return [];
        }
        $out = [];
        foreach (scandir($this->baseDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '_' || $entry[0] === '.') {
                continue;
            }
            if (is_dir($this->baseDir . '/' . $entry) && is_file($this->baseDir . '/' . $entry . '/migration.sql')) {
                $out[] = $entry;
            }
        }
        sort($out);
        return $out;
    }

    public function readMigrationSql(string $id): string
    {
        $p = $this->baseDir . '/' . $id . '/migration.sql';
        $src = file_get_contents($p);
        if ($src === false) {
            throw new \RuntimeException("Cannot read migration: {$p}");
        }
        return $src;
    }

    /** @param list<string> $sqlStatements */
    public function writeMigration(string $id, array $sqlStatements): string
    {
        $this->ensureDir();
        $dir = $this->baseDir . '/' . $id;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create migration dir: {$dir}");
        }
        $path = $dir . '/migration.sql';
        file_put_contents($path, implode("\n", $sqlStatements) . "\n");
        return $path;
    }

    public static function newId(string $slug, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now');
        $clean = preg_replace('/[^a-z0-9_]+/i', '_', $slug) ?? 'migration';
        $clean = trim((string) $clean, '_');
        if ($clean === '') {
            $clean = 'migration';
        }
        return $now->format('YmdHisv') . '_' . $clean;
    }
}
