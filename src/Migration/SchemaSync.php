<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Migration;

use PDO;
use Polidog\Tehilim\Driver\Driver;
use Polidog\Tehilim\Schema\Ast\Schema;
use Throwable;

/**
 * Destructive `push`: drops and re-creates every table to match the schema.
 * No history, no diffing — intended for prototyping and tests. Real
 * deployments should use the Migrator.
 */
final class SchemaSync
{
    public function __construct(
        private readonly Driver $driver,
        private readonly Schema $schema,
    ) {}

    public function push(bool $drop = true): void
    {
        $tables = TableBuilder::fromSchema($this->schema);
        $pdo = $this->driver->pdo();

        $pdo->beginTransaction();

        try {
            if ($drop) {
                foreach ($this->dropOrder($tables) as $existing) {
                    $this->runDdl($pdo, $this->driver->dropTableIfExistsSql($existing));
                }
            }
            foreach ($tables as $t) {
                $this->runDdl($pdo, $this->driver->createTableSql($t));
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }

    /**
     * Order live tables for dropping: schema tables children-first (reverse of
     * the FK-aware create order), then any leftover tables not in the schema.
     * SQLite ignores order; MySQL/PostgreSQL need children dropped first.
     *
     * @param list<TableDef> $tables create order (referenced tables first)
     *
     * @return list<string>
     */
    private function dropOrder(array $tables): array
    {
        $live = $this->driver->listTables();
        $liveSet = array_flip($live);

        $ordered = [];
        foreach (array_reverse($tables) as $t) {
            if (isset($liveSet[$t->name])) {
                $ordered[] = $t->name;
                unset($liveSet[$t->name]);
            }
        }
        foreach ($live as $name) {
            if (isset($liveSet[$name])) {
                $ordered[] = $name;
            }
        }

        return $ordered;
    }

    private function runDdl(PDO $pdo, string $sql): void
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    }
}
