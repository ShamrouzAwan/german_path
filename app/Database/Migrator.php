<?php

declare(strict_types=1);

namespace GermanPath\Database;

use GermanPath\Support\Logger;
use PDO;
use RuntimeException;

final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationPath,
        private readonly Logger $logger
    ) {
    }

    /** @return list<string> Applied migration versions in this run. */
    public function migrate(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY,
                filename TEXT NOT NULL,
                applied_at TEXT NOT NULL
            )'
        );

        $applied = $this->pdo->query('SELECT version FROM schema_migrations')
            ->fetchAll(PDO::FETCH_COLUMN);
        $applied = array_fill_keys(array_map('strval', $applied), true);
        $completed = [];

        $files = glob($this->migrationPath . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $filename = basename($file);
            if (!preg_match('/^(\d+)_.*\.sql$/', $filename, $matches)) {
                continue;
            }

            $version = $matches[1];
            if (isset($applied[$version])) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false || trim($sql) === '') {
                throw new RuntimeException("Migration {$filename} is empty or unreadable.");
            }

            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $statement = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (version, filename, applied_at)
                     VALUES (:version, :filename, :applied_at)'
                );
                $statement->execute([
                    ':version' => $version,
                    ':filename' => $filename,
                    ':applied_at' => gmdate('c'),
                ]);
                $this->pdo->commit();
                $completed[] = $version;
                $this->logger->info('Database migration applied', [
                    'version' => $version,
                    'filename' => $filename,
                ]);
            } catch (\Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
        }

        return $completed;
    }
}
