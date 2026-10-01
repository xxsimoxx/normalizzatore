<?php

declare(strict_types=1);

namespace Normalizzatore\Directory;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class SqliteAddressDirectory implements AddressDirectoryInterface
{
    private PDO $pdo;
    private ?PDOStatement $streetLookup = null;
    private ?PDOStatement $territorialLookup = null;

    public function __construct(
        string $sqlitePath,
        private readonly DirectoryKeyNormalizer $keyNormalizer = new DirectoryKeyNormalizer(),
    ) {
        if (!is_file($sqlitePath) || !is_readable($sqlitePath)) {
            throw new RuntimeException(sprintf('Directory database "%s" does not exist or is not readable.', $sqlitePath));
        }

        try {
            $this->pdo = new PDO($this->readOnlyDsn($sqlitePath), options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->pdo->exec('PRAGMA temp_store = MEMORY');
            $this->assertCompatibleSchema();
            $this->pdo->exec('PRAGMA query_only = ON');
        } catch (PDOException $exception) {
            throw new DirectorySchemaException('Unable to open or query the SQLite directory: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /** @return list<DirectoryEntry> */
    public function findByStreetCityProvince(string $street, string $city, string $province): array
    {
        $this->prepareStreetIndex();
        if ($this->streetLookup === null) {
            throw new DirectorySchemaException('Unable to prepare the temporary canonical street lookup index.');
        }

        $this->streetLookup->execute([
            ':vianum_key' => $this->keyNormalizer->normalize($street),
            ':citta_key' => $this->keyNormalizer->normalize($city),
            ':pr_key' => $this->keyNormalizer->normalize($province),
        ]);

        $entries = [];
        foreach ($this->streetLookup->fetchAll() as $row) {
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

    /** @return list<TerritorialEntry> */
    public function findTerritorialEntries(string $city): array
    {
        $this->prepareTerritorialIndex();

        if ($this->territorialLookup === null) {
            throw new DirectorySchemaException('Unable to prepare the temporary territorial directory index.');
        }

        $this->territorialLookup->execute([
            ':citta_key' => $this->keyNormalizer->normalize($city),
        ]);

        $entries = [];
        foreach ($this->territorialLookup->fetchAll() as $row) {
            $entries[] = new TerritorialEntry(
                $row['citta'],
                $row['pr'],
                $row['cap'],
                (int) $row['record_count'],
            );
        }

        return $entries;
    }

    private function prepareTerritorialIndex(): void
    {
        if ($this->territorialLookup !== null) {
            return;
        }

        try {
            // The main database is opened mode=ro; only this connection-local TEMP table is written.
            $this->pdo->exec('PRAGMA query_only = OFF');
            $this->pdo->beginTransaction();
            $this->pdo->exec(<<<'SQL'
                CREATE TEMP TABLE territorial_directory_groups AS
                SELECT citta_key, citta, pr, cap, COUNT(*) AS record_count
                FROM directory_entries
                GROUP BY citta_key, citta, pr, cap
                SQL);
            $this->pdo->exec(<<<'SQL'
                CREATE TEMP TABLE territorial_city_key_map (
                    source_key TEXT PRIMARY KEY,
                    canonical_key TEXT NOT NULL
                )
                SQL);
            $cityKeys = $this->pdo->query('SELECT DISTINCT citta_key FROM territorial_directory_groups')->fetchAll(PDO::FETCH_COLUMN);
            $insertCityKey = $this->pdo->prepare(<<<'SQL'
                INSERT INTO territorial_city_key_map (source_key, canonical_key)
                VALUES (:source_key, :canonical_key)
                SQL);
            foreach ($cityKeys as $cityKey) {
                $insertCityKey->execute([
                    ':source_key' => $cityKey,
                    ':canonical_key' => $this->keyNormalizer->normalize($cityKey),
                ]);
            }
            $this->pdo->exec(<<<'SQL'
                CREATE TEMP TABLE territorial_directory_entries AS
                SELECT m.canonical_key AS citta_key, g.citta, g.pr, g.cap, g.record_count
                FROM territorial_directory_groups AS g
                INNER JOIN territorial_city_key_map AS m ON m.source_key = g.citta_key
                SQL);
            $this->pdo->exec('DROP TABLE temp.territorial_city_key_map');
            $this->pdo->exec('DROP TABLE temp.territorial_directory_groups');
            $this->pdo->exec('CREATE INDEX temp.idx_canonical_territorial_city_key ON territorial_directory_entries (citta_key)');
            $lookup = $this->pdo->prepare(<<<'SQL'
                SELECT citta, pr, cap, record_count
                FROM territorial_directory_entries
                WHERE citta_key = :citta_key
                ORDER BY citta, pr, cap
                SQL);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (Throwable) {
                    // Keep the original preparation error.
                }
            }
            try {
                $this->pdo->exec('DROP TABLE IF EXISTS temp.territorial_directory_entries');
                $this->pdo->exec('DROP TABLE IF EXISTS temp.territorial_city_key_map');
                $this->pdo->exec('DROP TABLE IF EXISTS temp.territorial_directory_groups');
            } catch (Throwable) {
                // Keep the original preparation error.
            }

            if ($exception instanceof PDOException) {
                throw new DirectorySchemaException('Unable to build the temporary canonical territorial lookup index.', 0, $exception);
            }

            throw $exception;
        } finally {
            $this->pdo->exec('PRAGMA query_only = ON');
        }

        $this->territorialLookup = $lookup;
    }

    private function prepareStreetIndex(): void
    {
        if ($this->streetLookup !== null) {
            return;
        }

        try {
            $this->pdo->exec('PRAGMA query_only = OFF');
            $this->pdo->beginTransaction();
            $streetExpression = $this->sqliteOrthographyExpression('vianum_key');
            $cityExpression = $this->sqliteOrthographyExpression('citta_key');
            $provinceExpression = $this->sqliteOrthographyExpression('pr_key');
            $predicate = $this->orthographicChangePredicate(['vianum_key', 'citta_key', 'pr_key']);
            $this->pdo->exec(sprintf(
                'CREATE TEMP TABLE canonical_street_directory_entries AS SELECT id, %s AS vianum_key, %s AS citta_key, %s AS pr_key FROM directory_entries WHERE %s',
                $streetExpression,
                $cityExpression,
                $provinceExpression,
                $predicate,
            ));
            $this->pdo->exec('CREATE INDEX temp.idx_canonical_street_lookup ON canonical_street_directory_entries (vianum_key, citta_key, pr_key)');
            $lookup = $this->pdo->prepare(<<<'SQL'
                SELECT d.id, d.vianum, d.cap, d.citta, d.pr, d.pari_dispa, d.civico_da, d.civico_a
                FROM directory_entries AS d
                WHERE d.vianum_key = :vianum_key AND d.citta_key = :citta_key AND d.pr_key = :pr_key
                UNION ALL
                SELECT d.id, d.vianum, d.cap, d.citta, d.pr, d.pari_dispa, d.civico_da, d.civico_a
                FROM canonical_street_directory_entries AS c
                INNER JOIN directory_entries AS d ON d.id = c.id
                WHERE c.vianum_key = :vianum_key AND c.citta_key = :citta_key AND c.pr_key = :pr_key
                ORDER BY d.id ASC
                SQL);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (Throwable) {
                    // Keep the original preparation error.
                }
            }
            try {
                $this->pdo->exec('DROP TABLE IF EXISTS temp.canonical_street_directory_entries');
            } catch (Throwable) {
                // Keep the original preparation error.
            }

            if ($exception instanceof PDOException) {
                throw new DirectorySchemaException('Unable to build the temporary canonical street lookup index.', 0, $exception);
            }

            throw $exception;
        } finally {
            $this->pdo->exec('PRAGMA query_only = ON');
        }

        $this->streetLookup = $lookup;
    }

    /** @param list<string> $columns */
    private function orthographicChangePredicate(array $columns): string
    {
        $characters = implode('', $this->keyNormalizer->orthographicTriggerCharacters());
        $pattern = "'*[{$characters}]*'";
        $terms = array_map(static fn (string $column): string => sprintf('%s GLOB %s', $column, $pattern), $columns);

        return '(' . implode(' OR ', $terms) . ')';
    }

    private function sqliteOrthographyExpression(string $column): string
    {
        $expression = $column;
        foreach ($this->keyNormalizer->orthographicReplacements() as $source => $target) {
            $quotedSource = $this->pdo->quote($source);
            $quotedTarget = $this->pdo->quote($target);
            if ($quotedSource === false || $quotedTarget === false) {
                throw new RuntimeException('Unable to quote an orthographic replacement for SQLite.');
            }
            $expression = sprintf('replace(%s, %s, %s)', $expression, $quotedSource, $quotedTarget);
        }

        return sprintf(
            'CASE WHEN %s THEN %s ELSE %s END',
            $this->orthographicChangePredicate([$column]),
            $expression,
            $column,
        );
    }

    private function readOnlyDsn(string $sqlitePath): string
    {
        $uriPath = str_replace('%2F', '/', rawurlencode($sqlitePath));

        return 'sqlite:file:' . $uriPath . '?mode=ro';
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
