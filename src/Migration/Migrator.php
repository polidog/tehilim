<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Migration;

use PDO;
use Polidog\Tehilim\Driver\Driver;
use Polidog\Tehilim\Schema\Ast\Schema as SchemaAst;
use Polidog\Tehilim\Schema\Parser;

final class Migrator
{
    private const TRACKING_TABLE = '_tehilim_migrations';

    public function __construct(
        public readonly Driver $driver,
        public readonly MigrationStore $store,
        public readonly string $schemaPath,
    ) {}

    /**
     * Diff schema.tehilim against the last snapshot, write a new migration
     * file, apply it, record it, and snapshot.
     *
     * @return array{id:string, path:string, statements:int, skipped:bool}
     */
    public function dev(string $slug, ?\DateTimeImmutable $now = null): array
    {
        $newSchemaSrc = $this->readSchemaSource();
        $newSchema = Parser::parseString($newSchemaSrc);
        $oldSchemaSrc = $this->store->snapshotSchema();
        $oldSchema = $oldSchemaSrc === '' ? new SchemaAst() : Parser::parseString($oldSchemaSrc);

        $statements = (new SchemaDiff())->diff($oldSchema, $newSchema, $this->driver);
        $statements = array_values(array_filter($statements, static fn (string $s): bool => trim($s) !== ''));

        if ($statements === []) {
            return ['id' => '', 'path' => '', 'statements' => 0, 'skipped' => true];
        }

        $id = MigrationStore::newId($slug, $now);
        $path = $this->store->writeMigration($id, $statements);

        $this->ensureTracking();
        $this->applyStatements($statements);
        $this->record($id, implode("\n", $statements));

        $this->store->writeSnapshot($newSchemaSrc);

        return ['id' => $id, 'path' => $path, 'statements' => count($statements), 'skipped' => false];
    }

    /**
     * Apply every migration file not yet recorded in the tracking table.
     *
     * @return list<string> ids that were applied
     */
    public function deploy(): array
    {
        $this->ensureTracking();
        $applied = $this->appliedIds();
        $applied = array_flip($applied);

        $done = [];
        foreach ($this->store->listMigrations() as $id) {
            if (isset($applied[$id])) {
                continue;
            }
            $sql = $this->store->readMigrationSql($id);
            $statements = $this->splitSql($sql);
            $this->applyStatements($statements);
            $this->record($id, $sql);
            $done[] = $id;
        }
        return $done;
    }

    /**
     * @return list<array{id:string, applied:bool}>
     */
    public function status(): array
    {
        $this->ensureTracking();
        $applied = array_flip($this->appliedIds());
        $out = [];
        foreach ($this->store->listMigrations() as $id) {
            $out[] = ['id' => $id, 'applied' => isset($applied[$id])];
        }
        return $out;
    }

    /**
     * Drop every table tracked by the snapshot (plus the tracking table) and
     * re-apply all migrations.
     */
    public function reset(): void
    {
        $snap = $this->store->snapshotSchema();
        if ($snap !== '') {
            $schema = Parser::parseString($snap);
            foreach (array_reverse(TableBuilder::fromSchema($schema)) as $t) {
                $this->run($this->driver->dropTableIfExistsSql($t->name));
            }
        }
        $this->run($this->driver->dropTableIfExistsSql(self::TRACKING_TABLE));
        $this->deploy();
    }

    private function readSchemaSource(): string
    {
        $src = @file_get_contents($this->schemaPath);
        if ($src === false) {
            throw new \RuntimeException("Cannot read schema: {$this->schemaPath}");
        }
        return $src;
    }

    /** @return list<string> */
    private function appliedIds(): array
    {
        $pdo = $this->driver->pdo();
        $sql = sprintf(
            'SELECT %s FROM %s ORDER BY %s',
            $this->driver->quoteIdent('id'),
            $this->driver->quoteIdent(self::TRACKING_TABLE),
            $this->driver->quoteIdent('id'),
        );
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_map(strval(...), $rows);
    }

    private function ensureTracking(): void
    {
        $table = new TableDef(
            name: self::TRACKING_TABLE,
            columns: [
                new ColumnDef(name: 'id',         phpType: 'string',  nullable: false),
                new ColumnDef(name: 'applied_at', phpType: 'string',  nullable: false),
                new ColumnDef(name: 'checksum',   phpType: 'string',  nullable: false),
            ],
            primaryKey: 'id',
        );
        $this->run($this->driver->createTableIfNotExistsSql($table));
    }

    /** @param list<string> $statements */
    private function applyStatements(array $statements): void
    {
        $pdo = $this->driver->pdo();
        $pdo->beginTransaction();
        try {
            foreach ($statements as $sql) {
                $trim = trim($sql);
                if ($trim === '' || str_starts_with($trim, '--')) {
                    continue;
                }
                $this->run($sql);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function record(string $id, string $sql): void
    {
        $pdo = $this->driver->pdo();
        $sqlInsert = sprintf(
            'INSERT INTO %s (%s, %s, %s) VALUES (?, ?, ?)',
            $this->driver->quoteIdent(self::TRACKING_TABLE),
            $this->driver->quoteIdent('id'),
            $this->driver->quoteIdent('applied_at'),
            $this->driver->quoteIdent('checksum'),
        );
        $stmt = $pdo->prepare($sqlInsert);
        $stmt->execute([
            $id,
            (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            hash('sha256', $sql),
        ]);
    }

    private function run(string $sql): void
    {
        $stmt = $this->driver->pdo()->prepare($sql);
        $stmt->execute();
    }

    /**
     * Split a SQL file into individual statements at top-level semicolons.
     * Naive but sufficient for our generated DDL.
     *
     * @return list<string>
     */
    private function splitSql(string $sql): array
    {
        $statements = [];
        $buf = '';
        $inString = false;
        $stringChar = '';
        $len = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $buf .= $c;
            if ($inString) {
                if ($c === $stringChar) {
                    $inString = false;
                }
                continue;
            }
            if ($c === "'" || $c === '"') {
                $inString = true;
                $stringChar = $c;
                continue;
            }
            if ($c === ';') {
                $statements[] = $buf;
                $buf = '';
            }
        }
        if (trim($buf) !== '') {
            $statements[] = $buf;
        }
        return $statements;
    }
}
