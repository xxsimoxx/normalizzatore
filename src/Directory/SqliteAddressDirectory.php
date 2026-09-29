<?php

declare(strict_types=1);

namespace Normalizzatore\Directory;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

final class SqliteAddressDirectory implements AddressDirectoryInterface
{
    private PDO $pdo;
    private PDOStatement $lookup;

    public function __construct(
        string $sqlitePath,
        private readonly DirectoryKeyNormalizer $keyNormalizer = new DirectoryKeyNormalizer(),
    ) {
        if (!is_file($sqlitePath) || !is_readable($sqlitePath)) {
            throw new RuntimeException(sprintf('Directory database "%s" does not exist or is not readable.', $sqlitePath));
        }

        try {
            $this->pdo = new PDO('sqlite:' . $sqlitePath, options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->pdo->exec('PRAGMA query_only = ON');
            $this->assertCompatibleSchema();
            $this->lookup = $this->pdo->prepare(<<<'SQL'
                SELECT id, vianum, cap, citta, pr, pari_dispa, civico_da, civico_a
                FROM directory_entries
                WHERE vianum_key = :vianum_key AND citta_key = :citta_key AND pr_key = :pr_key
                ORDER BY id ASC
                SQL);
        } catch (PDOException $exception) {
            throw new DirectorySchemaException('Unable to open or query the SQLite directory: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /** @return list<DirectoryEntry> */
    public function findByStreetCityProvince(string $street, string $city, string $province): array
    {
        $this->lookup->execute([
            ':vianum_key' => $this->keyNormalizer->normalize($street),
            ':citta_key' => $this->keyNormalizer->normalize($city),
            ':pr_key' => $this->keyNormalizer->normalize($province),
        ]);

        $entries = [];
        foreach ($this->lookup->fetchAll() as $row) {
            $entries[] = new DirectoryEntry(
                (int) $row['id'],
                $row['vianum'],
                $row['cap'],
                $row['citta'],
                $row['pr'],
                $row['pari_dispa'],
                $row['civico_da'],
                $row['civico_a'],
            );
        }

        return $entries;
    }

    private function assertCompatibleSchema(): void
    {
        try {
            $version = $this->pdo->query("SELECT value FROM directory_metadata WHERE key = 'schema_version'")->fetchColumn();
        } catch (PDOException $exception) {
            throw new DirectorySchemaException('SQLite directory metadata is missing or unreadable; rebuild it with bin/import-directory.', 0, $exception);
        }

        if ($version !== SqliteDirectoryImporter::SCHEMA_VERSION) {
            $reported = $version === false ? 'missing' : (string) $version;
            throw new DirectorySchemaException(sprintf(
                'Unsupported SQLite directory schema version %s; expected %s. Rebuild it with bin/import-directory.',
                $reported,
                SqliteDirectoryImporter::SCHEMA_VERSION,
            ));
        }

        $columns = $this->pdo->query("PRAGMA table_info('directory_entries')")->fetchAll(PDO::FETCH_COLUMN, 1);
        $required = ['id', 'vianum', 'cap', 'citta', 'pr', 'pari_dispa', 'civico_da', 'civico_a', 'vianum_key', 'citta_key', 'pr_key'];
        if (array_diff($required, $columns) !== []) {
            throw new DirectorySchemaException('SQLite directory has an incompatible directory_entries schema; rebuild it with bin/import-directory.');
        }

        $indexColumns = $this->pdo->query("PRAGMA index_info('idx_directory_entries_lookup_key')")->fetchAll(PDO::FETCH_COLUMN, 2);
        if ($indexColumns !== ['vianum_key', 'citta_key', 'pr_key']) {
            throw new DirectorySchemaException('SQLite directory is missing the expected lookup-key index; rebuild it with bin/import-directory.');
        }
    }
}
