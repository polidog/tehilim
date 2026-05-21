<?php

declare(strict_types=1);

namespace Polidog\Tehilim\Migration;

use PDO;
use Polidog\Tehilim\Driver\Driver;
use Polidog\Tehilim\Schema\Ast\Schema;

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
                foreach (array_reverse($tables) as $t) {
                    $this->runDdl($pdo, $this->driver->dropTableIfExistsSql($t->name));
                }
            }
            foreach ($tables as $t) {
                $this->runDdl($pdo, $this->driver->createTableSql($t));
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function runDdl(PDO $pdo, string $sql): void
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
    }
}
