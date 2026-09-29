<?php

declare(strict_types=1);

namespace Normalizzatore\Directory;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class SqliteDirectoryImporter
{
    public const SCHEMA_VERSION = '1';

    public function import(string $dbfPath, string $sqlitePath): int
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('The pdo_sqlite PHP extension is required.');
        }

        $reader = new DbfReader($dbfPath);
        $directory = dirname($sqlitePath);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create destination directory "%s".', $directory));
        }
        if (!is_writable($directory)) {
            throw new RuntimeException(sprintf('Destination directory "%s" is not writable.', $directory));
        }

        $temporaryPath = $sqlitePath . '.tmp-' . bin2hex(random_bytes(6));
        $fingerprint = hash_file('sha256', $dbfPath);
        if ($fingerprint === false) {
            throw new RuntimeException(sprintf('Cannot calculate SHA-256 for DBF file "%s".', $dbfPath));
        }

        $pdo = null;
        try {
            $pdo = new PDO('sqlite:' . $temporaryPath, options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            // Build a disposable temporary database; failed builds are discarded before publication.
            $pdo->exec('PRAGMA journal_mode = OFF');
            $pdo->exec('PRAGMA synchronous = OFF');
            $pdo->exec('PRAGMA temp_store = MEMORY');
            $this->createSchema($pdo);

            $insert = $pdo->prepare(
                'INSERT INTO directory_entries '
                . '(vianum, cap, citta, pr, pari_dispa, civico_da, civico_a) '
                . 'VALUES (:vianum, :cap, :citta, :pr, :pari_dispa, :civico_da, :civico_a)',
            );
            $pdo->beginTransaction();
            $count = 0;
            foreach ($reader->records() as $record) {
                $insert->execute([
                    ':vianum' => $record->vianum,
                    ':cap' => $record->cap,
                    ':citta' => $record->citta,
                    ':pr' => $record->pr,
                    ':pari_dispa' => $record->pariDispa,
                    ':civico_da' => $record->civicoDa,
                    ':civico_a' => $record->civicoA,
                ]);
                $count++;
            }
            $pdo->commit();

            $pdo->exec('CREATE INDEX idx_directory_entries_lookup ON directory_entries (vianum, citta, pr)');
            $metadata = $pdo->prepare('INSERT INTO directory_metadata (key, value) VALUES (:key, :value)');
            $values = [
                'schema_version' => self::SCHEMA_VERSION,
                'record_count' => (string) $count,
                'source_record_count' => (string) $reader->declaredRecordCount(),
                'deleted_record_count' => (string) $reader->deletedRecordCount(),
                'source_encoding' => 'CP850',
                'dbf_version' => sprintf('0x%02X', $reader->dbfVersion()),
                'source_sha256' => $fingerprint,
            ];
            foreach ($values as $key => $value) {
                $metadata->execute([':key' => $key, ':value' => $value]);
            }

            $pdo = null;
            if (!@rename($temporaryPath, $sqlitePath)) {
                throw new RuntimeException(sprintf('Cannot move completed SQLite database to "%s".', $sqlitePath));
            }

            return $count;
        } catch (Throwable $exception) {
            if ($pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo = null;
            @unlink($temporaryPath);

            if ($exception instanceof RuntimeException) {
                throw $exception;
            }
            if ($exception instanceof PDOException) {
                throw new RuntimeException('SQLite directory import failed: ' . $exception->getMessage(), 0, $exception);
            }

            throw new RuntimeException('Directory import failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function createSchema(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE directory_entries (
                id INTEGER PRIMARY KEY,
                vianum TEXT NOT NULL,
                cap TEXT NOT NULL,
                citta TEXT NOT NULL,
                pr TEXT NOT NULL,
                pari_dispa TEXT NOT NULL,
                civico_da TEXT NOT NULL,
                civico_a TEXT NOT NULL
            )
            SQL);
        $pdo->exec(<<<'SQL'
            CREATE TABLE directory_metadata (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )
            SQL);
    }
}
