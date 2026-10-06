<?php

declare(strict_types=1);

namespace Normalizzatore\Directory;

use Normalizzatore\Address\StreetNameTokenizer;
use Normalizzatore\Resolution\FuzzyStreetCandidateQuery;
use Normalizzatore\Resolution\FuzzyStreetNameCandidate;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class SqliteAddressDirectory implements AddressDirectoryInterface, FuzzyStreetCandidateProvider
{
    private PDO $pdo;
    private ?PDOStatement $streetLookup = null;
    private ?PDOStatement $territorialLookup = null;
    private ?PDOStatement $fuzzyProvinceLookup = null;
    private ?PDOStatement $fuzzyLocalityLookup = null;
    private ?PDOStatement $fuzzyCandidateLookup = null;
    private ?PDOStatement $fuzzyCityCandidateLookup = null;
    private ?PDOStatement $fuzzyEntryLookup = null;
    private ?FuzzyStreetCatalogStatistics $fuzzyStatistics = null;

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

    public function findCandidates(FuzzyStreetCandidateQuery $query): FuzzyStreetCandidateSet
    {
        $cityKey = $this->keyNormalizer->normalize($query->city);
        $provinceKey = $this->keyNormalizer->normalize($query->province);
        if ($cityKey === '') {
            return new FuzzyStreetCandidateSet(FuzzyStreetCandidateSetStatus::NO_LOCALITY);
        }

        $this->prepareFuzzyCatalog();
        if ($this->fuzzyProvinceLookup === null || $this->fuzzyCandidateLookup === null) {
            throw new DirectorySchemaException('Unable to prepare the temporary fuzzy street catalog lookups.');
        }

        $this->fuzzyProvinceLookup->execute([':city_key' => $cityKey]);
        $provinceKeys = array_map('strval', $this->fuzzyProvinceLookup->fetchAll(PDO::FETCH_COLUMN));
        $this->fuzzyProvinceLookup->closeCursor();
        if ($provinceKeys === []) {
            return new FuzzyStreetCandidateSet(FuzzyStreetCandidateSetStatus::NO_LOCALITY);
        }

        if ($provinceKey !== '') {
            if (!in_array($provinceKey, $provinceKeys, true)) {
                return new FuzzyStreetCandidateSet(
                    FuzzyStreetCandidateSetStatus::PROVINCE_CONFLICT,
                    diagnosticCandidates: $this->loadFuzzyCandidates($cityKey, null, $query),
                    cityKey: $cityKey,
                    provinceKeys: $provinceKeys,
                );
            }

            return new FuzzyStreetCandidateSet(
                FuzzyStreetCandidateSetStatus::AVAILABLE,
                candidates: $this->loadFuzzyCandidates($cityKey, $provinceKey, $query),
                cityKey: $cityKey,
                provinceKey: $provinceKey,
                provinceKeys: $provinceKeys,
            );
        }

        if (count($provinceKeys) !== 1) {
            return new FuzzyStreetCandidateSet(
                FuzzyStreetCandidateSetStatus::AMBIGUOUS_LOCALITY,
                cityKey: $cityKey,
                provinceKeys: $provinceKeys,
            );
        }

        return new FuzzyStreetCandidateSet(
            FuzzyStreetCandidateSetStatus::AVAILABLE,
            candidates: $this->loadFuzzyCandidates($cityKey, $provinceKeys[0], $query),
            cityKey: $cityKey,
            provinceKey: $provinceKeys[0],
            provinceKeys: $provinceKeys,
        );
    }

    public function findEntries(FuzzyStreetCandidateSet $set, FuzzyStreetNameCandidate $candidate): array
    {
        if ($set->status !== FuzzyStreetCandidateSetStatus::AVAILABLE
            || $set->cityKey === null
            || $set->provinceKey === null
            || !in_array($candidate->streetName->canonicalName, array_map(
                static fn (FuzzyStreetNameCandidate $item): string => $item->streetName->canonicalName,
                $set->candidates,
            ), true)) {
            throw new \InvalidArgumentException('Directory entries can only be loaded for an applicable candidate in an available locality.');
        }

        $this->prepareFuzzyCatalog();
        if ($this->fuzzyLocalityLookup === null || $this->fuzzyEntryLookup === null) {
            throw new DirectorySchemaException('Unable to prepare the temporary fuzzy street entry lookup.');
        }

        $this->fuzzyLocalityLookup->execute([
            ':city_key' => $set->cityKey,
            ':province_key' => $set->provinceKey,
        ]);
        $localityId = $this->fuzzyLocalityLookup->fetchColumn();
        $this->fuzzyLocalityLookup->closeCursor();
        if ($localityId === false) {
            throw new DirectorySchemaException('The selected fuzzy street candidate locality is absent from its temporary catalog.');
        }

        $this->fuzzyEntryLookup->execute([
            ':locality_id' => (int) $localityId,
            ':canonical_name_direct' => $candidate->streetName->canonicalName,
            ':canonical_name_alias' => $candidate->streetName->canonicalName,
        ]);
        $entries = [];
        foreach ($this->fuzzyEntryLookup->fetchAll() as $row) {
            $entries[] = $this->directoryEntryFromRow($row);
        }
        $this->fuzzyEntryLookup->closeCursor();

        return $entries;
    }

    public function fuzzyCatalogStatistics(): ?FuzzyStreetCatalogStatistics
    {
        return $this->fuzzyStatistics;
    }

    /** @return list<FuzzyStreetNameCandidate> */
    private function loadFuzzyCandidates(string $cityKey, ?string $provinceKey, FuzzyStreetCandidateQuery $query): array
    {
        if ($this->fuzzyCandidateLookup === null) {
            throw new DirectorySchemaException('Unable to prepare the temporary fuzzy street candidate lookup.');
        }

        $lookup = $provinceKey === null ? $this->fuzzyCityCandidateLookup : $this->fuzzyCandidateLookup;
        if ($lookup === null) {
            throw new DirectorySchemaException('Unable to prepare the temporary fuzzy street candidate lookup.');
        }
        if ($provinceKey !== null) {
            if ($this->fuzzyLocalityLookup === null) {
                throw new DirectorySchemaException('Unable to prepare the temporary fuzzy street locality lookup.');
            }
            $this->fuzzyLocalityLookup->execute([':city_key' => $cityKey, ':province_key' => $provinceKey]);
            $localityId = $this->fuzzyLocalityLookup->fetchColumn();
            $this->fuzzyLocalityLookup->closeCursor();
            if ($localityId === false) {
                return [];
            }
            $lookup->execute([
                ':locality_id' => (int) $localityId,
                ':street_type' => $query->street->streetType->value,
                ':token_count' => $query->street->tokenCount(),
            ]);
        } else {
            $lookup->execute([
                ':city_key' => $cityKey,
                ':street_type' => $query->street->streetType->value,
                ':token_count' => $query->street->tokenCount(),
            ]);
        }
        $tokenizer = new StreetNameTokenizer($this->keyNormalizer);
        $candidates = [];
        foreach ($lookup->fetchAll(PDO::FETCH_COLUMN) as $canonicalName) {
            $tokenized = $tokenizer->tokenize((string) $canonicalName);
            if ($tokenized === null) {
                throw new DirectorySchemaException('A fuzzy catalog street name could not be tokenized consistently.');
            }
            $candidates[] = new FuzzyStreetNameCandidate($tokenized);
        }
        $lookup->closeCursor();

        return $candidates;
    }

    private function prepareFuzzyCatalog(): void
    {
        if ($this->fuzzyProvinceLookup !== null) {
            return;
        }

        $statistics = [
            'sourceLogicalNames' => 0,
            'includedSourceNames' => 0,
            'excludedSourceNames' => 0,
            'canonicalLogicalNames' => 0,
            'excludedByPrefix' => [],
            'tokenCountDistribution' => [],
        ];
        $createdTempTables = [];
        try {
            // Only TEMP data is written. The persistent database remains opened mode=ro.
            $this->pdo->exec('PRAGMA query_only = OFF');
            $this->pdo->beginTransaction();
            $this->pdo->exec(<<<'SQL'
                CREATE TEMP TABLE fuzzy_city_provinces (
                    id INTEGER PRIMARY KEY,
                    city_key TEXT NOT NULL,
                    province_key TEXT NOT NULL,
                    UNIQUE (city_key, province_key)
                )
                SQL);
            $createdTempTables[] = 'fuzzy_city_provinces';
            $this->pdo->exec(<<<'SQL'
                CREATE TEMP TABLE fuzzy_street_names (
                    locality_id INTEGER NOT NULL,
                    canonical_name TEXT NOT NULL,
                    street_type TEXT NOT NULL,
                    token_count INTEGER NOT NULL,
                    PRIMARY KEY (locality_id, street_type, token_count, canonical_name)
                ) WITHOUT ROWID
                SQL);
            $createdTempTables[] = 'fuzzy_street_names';
            $this->pdo->exec(<<<'SQL'
                CREATE TEMP TABLE fuzzy_locality_aliases (
                    locality_id INTEGER NOT NULL,
                    citta_key TEXT NOT NULL,
                    pr_key TEXT NOT NULL,
                    PRIMARY KEY (locality_id, citta_key, pr_key)
                )
                SQL);
            $createdTempTables[] = 'fuzzy_locality_aliases';
            $this->pdo->exec(<<<'SQL'
                CREATE TEMP TABLE fuzzy_street_aliases (
                    locality_id INTEGER NOT NULL,
                    canonical_name TEXT NOT NULL,
                    vianum_key TEXT NOT NULL,
                    PRIMARY KEY (locality_id, canonical_name, vianum_key)
                )
                SQL);
            $createdTempTables[] = 'fuzzy_street_aliases';

            $insertLocality = $this->pdo->prepare('INSERT OR IGNORE INTO temp.fuzzy_city_provinces (city_key, province_key) VALUES (:city_key, :province_key)');
            $findLocality = $this->pdo->prepare('SELECT id FROM temp.fuzzy_city_provinces WHERE city_key = :city_key AND province_key = :province_key');
            $insertLocalityAlias = $this->pdo->prepare('INSERT OR IGNORE INTO temp.fuzzy_locality_aliases (locality_id, citta_key, pr_key) VALUES (:locality_id, :citta_key, :pr_key)');
            $insertName = $this->pdo->prepare(<<<'SQL'
                INSERT OR IGNORE INTO temp.fuzzy_street_names (locality_id, canonical_name, street_type, token_count)
                VALUES (:locality_id, :canonical_name, :street_type, :token_count)
                SQL);
            $insertAlias = $this->pdo->prepare(<<<'SQL'
                INSERT OR IGNORE INTO temp.fuzzy_street_aliases (locality_id, canonical_name, vianum_key)
                VALUES (:locality_id, :canonical_name, :vianum_key)
                SQL);
            $tokenizer = new StreetNameTokenizer($this->keyNormalizer);
            // Locality strings repeat across almost every street. Resolve each source
            // city/province key pair once and retain only this small map in PHP.
            $localityIds = [];
            $localityRows = $this->pdo->query(<<<'SQL'
                SELECT citta_key, pr_key, MIN(citta) AS citta, MIN(pr) AS pr
                FROM main.directory_entries
                GROUP BY citta_key, pr_key
                ORDER BY citta_key, pr_key
                SQL);
            while (($localityRow = $localityRows->fetch(PDO::FETCH_ASSOC)) !== false) {
                $cityKey = $this->keyNormalizer->normalize($localityRow['citta']);
                $provinceKey = $this->keyNormalizer->normalize($localityRow['pr']);
                $insertLocality->execute([':city_key' => $cityKey, ':province_key' => $provinceKey]);
                if ($insertLocality->rowCount() === 1) {
                    $localityId = (int) $this->pdo->lastInsertId();
                } else {
                    $findLocality->execute([':city_key' => $cityKey, ':province_key' => $provinceKey]);
                    $localityId = $findLocality->fetchColumn();
                    if ($localityId === false) {
                        throw new DirectorySchemaException('Unable to identify a fuzzy catalog locality after insertion.');
                    }
                    $localityId = (int) $localityId;
                }
                $localityIds[$localityRow['citta_key'] . "\0" . $localityRow['pr_key']] = $localityId;
                $insertLocalityAlias->execute([
                    ':locality_id' => $localityId,
                    ':citta_key' => $localityRow['citta_key'],
                    ':pr_key' => $localityRow['pr_key'],
                ]);
            }
            $localityRows->closeCursor();

            $sourceRows = $this->pdo->query(<<<'SQL'
                SELECT vianum_key, citta_key, pr_key, MIN(vianum) AS vianum
                FROM main.directory_entries
                GROUP BY vianum_key, citta_key, pr_key
                ORDER BY citta_key, pr_key, vianum_key
                SQL);
            while (($row = $sourceRows->fetch(PDO::FETCH_ASSOC)) !== false) {
                ++$statistics['sourceLogicalNames'];
                $localityId = $localityIds[$row['citta_key'] . "\0" . $row['pr_key']] ?? null;
                if ($localityId === null) {
                    throw new DirectorySchemaException('A fuzzy source street references an unknown source locality.');
                }

                $street = $tokenizer->tokenize($row['vianum']);
                if ($street === null) {
                    ++$statistics['excludedSourceNames'];
                    $prefix = strtok($this->keyNormalizer->normalize($row['vianum']), ' ') ?: '(EMPTY)';
                    $statistics['excludedByPrefix'][$prefix] = ($statistics['excludedByPrefix'][$prefix] ?? 0) + 1;
                    continue;
                }

                ++$statistics['includedSourceNames'];
                $count = $street->tokenCount();
                $statistics['tokenCountDistribution'][$count] = ($statistics['tokenCountDistribution'][$count] ?? 0) + 1;
                $insertName->execute([
                    ':locality_id' => $localityId,
                    ':canonical_name' => $street->canonicalName,
                    ':street_type' => $street->streetType->value,
                    ':token_count' => $count,
                ]);
                if ($insertName->rowCount() === 1) {
                    ++$statistics['canonicalLogicalNames'];
                }
                // The canonical key itself is recovered directly from the persistent
                // composite index. Only non-canonical spellings need an alias row.
                if ($row['vianum_key'] !== $street->canonicalName) {
                    $insertAlias->execute([
                        ':locality_id' => $localityId,
                        ':canonical_name' => $street->canonicalName,
                        ':vianum_key' => $row['vianum_key'],
                    ]);
                }
            }
            $sourceRows->closeCursor();

            $provinceLookup = $this->pdo->prepare('SELECT province_key FROM temp.fuzzy_city_provinces WHERE city_key = :city_key ORDER BY province_key');
            $localityLookup = $this->pdo->prepare('SELECT id FROM temp.fuzzy_city_provinces WHERE city_key = :city_key AND province_key = :province_key');
            $candidateLookup = $this->pdo->prepare('SELECT canonical_name FROM temp.fuzzy_street_names WHERE locality_id = :locality_id AND street_type = :street_type AND token_count = :token_count ORDER BY canonical_name ASC');
            $cityCandidateLookup = $this->pdo->prepare(<<<'SQL'
                SELECT DISTINCT n.canonical_name
                FROM temp.fuzzy_city_provinces AS l
                INNER JOIN temp.fuzzy_street_names AS n ON n.locality_id = l.id
                WHERE l.city_key = :city_key AND n.street_type = :street_type AND n.token_count = :token_count
                ORDER BY n.canonical_name ASC
                SQL);
            $entryLookup = $this->pdo->prepare(<<<'SQL'
                SELECT d.id, d.vianum, d.cap, d.citta, d.pr, d.pari_dispa, d.civico_da, d.civico_a
                FROM temp.fuzzy_locality_aliases AS l
                INNER JOIN main.directory_entries AS d INDEXED BY idx_directory_entries_lookup_key
                    ON d.citta_key = l.citta_key AND d.pr_key = l.pr_key
                WHERE l.locality_id = :locality_id AND d.vianum_key = :canonical_name_direct
                UNION ALL
                SELECT d.id, d.vianum, d.cap, d.citta, d.pr, d.pari_dispa, d.civico_da, d.civico_a
                FROM temp.fuzzy_street_aliases AS a
                INNER JOIN temp.fuzzy_locality_aliases AS l ON l.locality_id = a.locality_id
                INNER JOIN main.directory_entries AS d INDEXED BY idx_directory_entries_lookup_key
                    ON d.vianum_key = a.vianum_key AND d.citta_key = l.citta_key AND d.pr_key = l.pr_key
                WHERE a.locality_id = :locality_id AND a.canonical_name = :canonical_name_alias
                    AND a.vianum_key <> :canonical_name_alias
                ORDER BY d.id ASC
                SQL);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                try {
                    $this->pdo->rollBack();
                } catch (Throwable) {
                    // Preserve the original preparation error.
                }
            }
            foreach (array_reverse($createdTempTables) as $table) {
                try {
                    $this->pdo->exec('DROP TABLE IF EXISTS temp.' . $table);
                } catch (Throwable) {
                    // Preserve the original preparation error.
                }
            }

            if ($exception instanceof PDOException) {
                throw new DirectorySchemaException('Unable to build the temporary fuzzy street candidate catalog.', 0, $exception);
            }
            throw $exception;
        } finally {
            $this->pdo->exec('PRAGMA query_only = ON');
        }

        ksort($statistics['excludedByPrefix'], SORT_STRING);
        ksort($statistics['tokenCountDistribution'], SORT_NUMERIC);
        $this->fuzzyStatistics = new FuzzyStreetCatalogStatistics(...$statistics);
        $this->fuzzyProvinceLookup = $provinceLookup;
        $this->fuzzyLocalityLookup = $localityLookup;
        $this->fuzzyCandidateLookup = $candidateLookup;
        $this->fuzzyCityCandidateLookup = $cityCandidateLookup;
        $this->fuzzyEntryLookup = $entryLookup;
    }

    /** @param array<string, mixed> $row */
    private function directoryEntryFromRow(array $row): DirectoryEntry
    {
        return new DirectoryEntry(
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
            $this->pdo->exec(<<<'SQL'
                CREATE TEMP TABLE canonical_street_directory_entries (
                    id INTEGER PRIMARY KEY,
                    vianum_key TEXT NOT NULL,
                    citta_key TEXT NOT NULL,
                    pr_key TEXT NOT NULL
                )
                SQL);
            $predicate = $this->orthographicChangePredicate(['vianum_key', 'citta_key', 'pr_key']);
            $affectedRows = $this->pdo->query(
                'SELECT id, vianum_key, citta_key, pr_key FROM main.directory_entries WHERE ' . $predicate . ' ORDER BY id ASC',
            );
            $insertCanonicalRow = $this->pdo->prepare(<<<'SQL'
                INSERT INTO temp.canonical_street_directory_entries (id, vianum_key, citta_key, pr_key)
                VALUES (:id, :vianum_key, :citta_key, :pr_key)
                SQL);
            while (($row = $affectedRows->fetch(PDO::FETCH_ASSOC)) !== false) {
                $insertCanonicalRow->execute([
                    ':id' => (int) $row['id'],
                    ':vianum_key' => $this->keyNormalizer->normalize($row['vianum_key']),
                    ':citta_key' => $this->keyNormalizer->normalize($row['citta_key']),
                    ':pr_key' => $this->keyNormalizer->normalize($row['pr_key']),
                ]);
            }
            $affectedRows->closeCursor();
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
