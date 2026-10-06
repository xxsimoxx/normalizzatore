<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Directory;

use Normalizzatore\Address\StreetNameTokenizer;
use Normalizzatore\Directory\DirectoryEntry;
use Normalizzatore\Directory\FuzzyStreetCandidateSetStatus;
use Normalizzatore\Directory\SqliteAddressDirectory;
use Normalizzatore\Resolution\AbbreviationStreetMatcher;
use Normalizzatore\Resolution\FuzzyStreetCandidateQuery;
use Normalizzatore\Resolution\FuzzyStreetNameCandidate;
use Normalizzatore\Resolution\FuzzyStreetResolutionStatus;
use Normalizzatore\Resolution\TypoStreetMatcher;
use Normalizzatore\Tests\Support\SqliteDirectoryFixture;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteFuzzyStreetCandidateProviderTest extends TestCase
{
    private string $directory;
    private string $database;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/normalizzatore-fuzzy-directory-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->database = $this->directory . '/directory.sqlite';
        SqliteDirectoryFixture::create($this->database, [
            SqliteDirectoryFixture::row('VIA ENRICO FERMI', '10100', 'TORINO', 'TO', 'D', '1', '19'),
            SqliteDirectoryFixture::row('VIA ENRICO FERMI', '10101', 'TORINO', 'TO', 'P', '2', '20'),
            SqliteDirectoryFixture::row('VIA ENRICO FERMI', '10102', 'TORINO', 'TO', 'D', '21', '99'),
            SqliteDirectoryFixture::row('VIA EDOARDO FERMI', '10103', 'TORINO', 'TO', 'T', '', ''),
            SqliteDirectoryFixture::row('VIA GIUSEPPE GARIBALDI', '00100', 'UNIV', 'UV', 'T', '', ''),
            SqliteDirectoryFixture::row('VIA CAPPUCCINA', '20100', 'MILANO', 'MI', 'T', '', ''),
            SqliteDirectoryFixture::row('VIA GAETA', '30100', 'TIE', 'TI', 'T', '', ''),
            SqliteDirectoryFixture::row('VIA ZATTA', '30100', 'TIE', 'TI', 'T', '', ''),
            SqliteDirectoryFixture::row("VIA D'ANNUNZIO", '00123', 'Torpè', 'NU', 'T', '', ''),
            SqliteDirectoryFixture::row('VIA D`ANNUNZIO', '00124', 'Torpè', 'NU', 'T', '', ''),
            SqliteDirectoryFixture::row('VIA OMNIPRESENTE', '40100', 'MULTI', 'AA', 'T', '', ''),
            SqliteDirectoryFixture::row('VIA OMNIPRESENTE', '40200', 'MULTI', 'BB', 'T', '', ''),
            SqliteDirectoryFixture::row('GALLERIA NON SUPPORTATA', '50100', 'TORINO', 'TO', 'T', '', ''),
            SqliteDirectoryFixture::row('VIA ROMA', '10100', 'TORINO', 'TO', 'T', '', ''),
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function testCatalogIsLazyAndReusedAfterDeterministicLookups(): void
    {
        $directory = new SqliteAddressDirectory($this->database);
        $pdo = $this->connection($directory);
        self::assertSame(0, $this->tempObjectCount($pdo, 'fuzzy*'));

        self::assertSame(
            FuzzyStreetCandidateSetStatus::NO_LOCALITY,
            $directory->findCandidates($this->query('VIA E. FERMI', '', 'TO'))->status,
        );
        self::assertSame(0, $this->tempObjectCount($pdo, 'fuzzy*'), 'An empty city does not build a useless catalog.');

        $directory->findByStreetCityProvince('VIA ENRICO FERMI', 'TORINO', 'TO');
        self::assertSame(0, $this->tempObjectCount($pdo, 'fuzzy*'));

        $directory->findCandidates($this->query('VIA E. FERMI', 'TORINO', 'TO'));
        $objectsAfterFirst = $this->tempObjectCount($pdo, 'fuzzy*');
        self::assertGreaterThan(0, $objectsAfterFirst);
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM sqlite_temp_master WHERE type='table' AND name='fuzzy_street_names'")->fetchColumn());

        $directory->findCandidates($this->query('VIA E. FERMI', 'TORINO', 'TO'));
        self::assertSame($objectsAfterFirst, $this->tempObjectCount($pdo, 'fuzzy*'));
    }

    public function testAggregatesLogicalNamesAndRecoversEveryPhysicalDirectoryEntry(): void
    {
        $directory = new SqliteAddressDirectory($this->database);
        $set = $directory->findCandidates($this->query('VIA E. FERMI', 'TORINO', 'TO'));

        self::assertSame(FuzzyStreetCandidateSetStatus::AVAILABLE, $set->status);
        self::assertSame(['VIA EDOARDO FERMI', 'VIA ENRICO FERMI'], $this->candidateNames($set->candidates));
        self::assertNotContains('VIA ROMA', $this->candidateNames($set->candidates), 'Different nominal token counts are filtered by the provider.');
        self::assertSame(0, (int) $this->connection($directory)->query(
            "SELECT COUNT(*) FROM temp.fuzzy_street_aliases WHERE canonical_name = 'VIA ENRICO FERMI'",
        )->fetchColumn(), 'The canonical street key is looked up directly without storing a redundant alias.');
        $enrico = $this->candidate('VIA ENRICO FERMI');
        $entries = $directory->findEntries($set, $enrico);

        self::assertCount(3, $entries);
        self::assertContainsOnlyInstancesOf(DirectoryEntry::class, $entries);
        self::assertSame([1, 2, 3], array_map(static fn (DirectoryEntry $entry): int => $entry->id, $entries));
        self::assertSame(['10100', '10101', '10102'], array_map(static fn (DirectoryEntry $entry): string => $entry->cap, $entries));
    }

    public function testCanonicalCollisionKeepsEveryOriginalDirectoryRow(): void
    {
        $directory = new SqliteAddressDirectory($this->database);
        $set = $directory->findCandidates($this->query("VIA D'ANNUNZIO", "TORPE'", 'NU'));
        self::assertSame(FuzzyStreetCandidateSetStatus::AVAILABLE, $set->status);
        self::assertSame(["VIA D'ANNUNZIO"], $this->candidateNames($set->candidates));

        $entries = $directory->findEntries($set, $set->candidates[0]);
        self::assertSame(["VIA D'ANNUNZIO", 'VIA D`ANNUNZIO'], array_map(static fn (DirectoryEntry $entry): string => $entry->vianum, $entries));
        self::assertSame(['00123', '00124'], array_map(static fn (DirectoryEntry $entry): string => $entry->cap, $entries));
        self::assertSame(1, (int) $this->connection($directory)->query(
            "SELECT COUNT(*) FROM temp.fuzzy_street_aliases WHERE canonical_name = 'VIA D''ANNUNZIO'",
        )->fetchColumn(), 'Only the non-canonical backtick spelling requires an alias.');
    }

    public function testCityAndProvinceGeographyDistinguishesAvailableConflictAndAmbiguity(): void
    {
        $directory = new SqliteAddressDirectory($this->database);

        $exactProvince = $directory->findCandidates($this->query('VIA E. FERMI', 'TORINO', 'TO'));
        self::assertSame(FuzzyStreetCandidateSetStatus::AVAILABLE, $exactProvince->status);
        self::assertSame(['TO'], $exactProvince->provinceKeys);

        $conflict = $directory->findCandidates($this->query('VIA E. FERMI', 'TORINO', 'XX'));
        self::assertSame(FuzzyStreetCandidateSetStatus::PROVINCE_CONFLICT, $conflict->status);
        self::assertSame([], $conflict->candidates);
        self::assertSame(['TO'], $conflict->provinceKeys);
        self::assertSame(['VIA EDOARDO FERMI', 'VIA ENRICO FERMI'], $this->candidateNames($conflict->diagnosticCandidates));
        try {
            $directory->findEntries($conflict, $conflict->diagnosticCandidates[0]);
            self::fail('Diagnostic-only candidates must not be usable to load directory evidence.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('applicable candidate in an available locality', $exception->getMessage());
        }

        $uniqueWithoutProvince = $directory->findCandidates($this->query('VIA GIUSEPPE GARIBALDI', 'UNIV', ''));
        self::assertSame(FuzzyStreetCandidateSetStatus::AVAILABLE, $uniqueWithoutProvince->status);
        self::assertSame('UV', $uniqueWithoutProvince->provinceKey);

        $ambiguousWithoutProvince = $directory->findCandidates($this->query('VIA OMNIPRESENTE', 'MULTI', ''));
        self::assertSame(FuzzyStreetCandidateSetStatus::AMBIGUOUS_LOCALITY, $ambiguousWithoutProvince->status);
        self::assertSame(['AA', 'BB'], $ambiguousWithoutProvince->provinceKeys);
        self::assertSame([], $ambiguousWithoutProvince->candidates);

        $explicitProvince = $directory->findCandidates($this->query('VIA OMNIPRESENTE', 'MULTI', 'AA'));
        self::assertSame(FuzzyStreetCandidateSetStatus::AVAILABLE, $explicitProvince->status);
        self::assertSame(['AA', 'BB'], $explicitProvince->provinceKeys);
        self::assertSame(['VIA OMNIPRESENTE'], $this->candidateNames($explicitProvince->candidates));
        self::assertSame(['40100'], array_map(
            static fn (DirectoryEntry $entry): string => $entry->cap,
            $directory->findEntries($explicitProvince, $explicitProvince->candidates[0]),
        ));

        $missingCity = $directory->findCandidates($this->query('VIA NON ESISTENTE', 'ASSENTE', 'XX'));
        self::assertSame(FuzzyStreetCandidateSetStatus::NO_LOCALITY, $missingCity->status);
    }

    public function testProviderCandidatesInteroperateWithPureMatchers(): void
    {
        $directory = new SqliteAddressDirectory($this->database);
        $abbreviationQuery = $this->query('VIA G. GARIBALDI', 'UNIV', 'UV');
        $abbreviationCandidates = $directory->findCandidates($abbreviationQuery);
        $abbreviation = (new AbbreviationStreetMatcher())->match($abbreviationQuery->street, $abbreviationCandidates->candidates);
        self::assertSame(FuzzyStreetResolutionStatus::MATCH, $abbreviation->status);
        self::assertSame('VIA GIUSEPPE GARIBALDI', $abbreviation->match?->candidate->canonicalName);

        $typoQuery = $this->query('VIA CAPUCCINA', 'MILANO', 'MI');
        $typoCandidates = $directory->findCandidates($typoQuery);
        $typo = (new TypoStreetMatcher())->match($typoQuery->street, $typoCandidates->candidates);
        self::assertSame(FuzzyStreetResolutionStatus::MATCH, $typo->status);
        self::assertSame('VIA CAPPUCCINA', $typo->match?->candidate->canonicalName);

        $tieQuery = $this->query('VIA GATTA', 'TIE', 'TI');
        $tieCandidates = $directory->findCandidates($tieQuery);
        $tie = (new TypoStreetMatcher())->match($tieQuery->street, $tieCandidates->candidates);
        self::assertSame(FuzzyStreetResolutionStatus::AMBIGUOUS, $tie->status);
        self::assertSame(['VIA GAETA', 'VIA ZATTA'], array_map(
            static fn ($evidence): string => $evidence->candidate->canonicalName,
            $tie->ambiguousCandidates,
        ));
    }

    public function testCandidateLookupUsesTheCompactCompositePrimaryKey(): void
    {
        $directory = new SqliteAddressDirectory($this->database);
        $directory->findCandidates($this->query('VIA E. FERMI', 'TORINO', 'TO'));
        $pdo = $this->connection($directory);
        $plan = $pdo->query(<<<'SQL'
            EXPLAIN QUERY PLAN
            SELECT canonical_name FROM temp.fuzzy_street_names
            WHERE locality_id=(SELECT id FROM temp.fuzzy_city_provinces WHERE city_key='TORINO' AND province_key='TO')
                AND street_type='VIA' AND token_count=2
            ORDER BY canonical_name
            SQL)->fetchAll(PDO::FETCH_COLUMN, 3);

        self::assertStringContainsString('PRIMARY KEY', implode(' ', $plan));
    }

    public function testFailedCatalogBuildCleansPartialTempTablesAndCanBeRetried(): void
    {
        $writer = new PDO('sqlite:' . $this->database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $writer->exec("UPDATE directory_entries SET citta = CAST(X'FF' AS TEXT) WHERE citta_key = 'TORINO'");
        unset($writer);

        $directory = new SqliteAddressDirectory($this->database);
        $pdo = $this->connection($directory);

        try {
            $directory->findCandidates($this->query('VIA E. FERMI', 'TORINO', 'TO'));
            self::fail('Expected invalid UTF-8 in a stored city value to fail catalog canonicalization.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Directory key contains invalid UTF-8.', $exception->getMessage());
        }

        foreach (['fuzzy_street_names', 'fuzzy_city_provinces', 'fuzzy_locality_aliases', 'fuzzy_street_aliases'] as $table) {
            self::assertFalse($pdo->query("SELECT 1 FROM sqlite_temp_master WHERE type='table' AND name='" . $table . "'")->fetchColumn());
        }
        self::assertSame(1, (int) $pdo->query('PRAGMA query_only')->fetchColumn());

        $writer = new PDO('sqlite:' . $this->database, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $writer->exec("UPDATE directory_entries SET citta = 'TORINO' WHERE citta_key = 'TORINO'");
        unset($writer);
        self::assertSame(FuzzyStreetCandidateSetStatus::AVAILABLE, $directory->findCandidates($this->query('VIA E. FERMI', 'TORINO', 'TO'))->status);
    }

    public function testCatalogCleanupDoesNotDropAnUnownedConflictingTempTable(): void
    {
        $directory = new SqliteAddressDirectory($this->database);
        $pdo = $this->connection($directory);
        $pdo->exec('PRAGMA query_only = OFF');
        $pdo->exec('CREATE TEMP TABLE fuzzy_street_names (owned_by_test INTEGER)');
        $pdo->exec('PRAGMA query_only = ON');

        try {
            $directory->findCandidates($this->query('VIA E. FERMI', 'TORINO', 'TO'));
            self::fail('Expected catalog initialization to fail on an existing TEMP table name.');
        } catch (\Normalizzatore\Directory\DirectorySchemaException $exception) {
            self::assertSame('Unable to build the temporary fuzzy street candidate catalog.', $exception->getMessage());
        }

        self::assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_temp_master WHERE type='table' AND name='fuzzy_street_names'",
        )->fetchColumn());
        self::assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_temp_master WHERE type='table' AND name='fuzzy_city_provinces'",
        )->fetchColumn());
        $pdo->exec('PRAGMA query_only = OFF');
        $pdo->exec('DROP TABLE temp.fuzzy_street_names');
        $pdo->exec('PRAGMA query_only = ON');
        self::assertSame(FuzzyStreetCandidateSetStatus::AVAILABLE, $directory->findCandidates($this->query('VIA E. FERMI', 'TORINO', 'TO'))->status);
    }

    public function testCatalogStatisticsIncludeCoverageAndUnsupportedPrefixes(): void
    {
        $directory = new SqliteAddressDirectory($this->database);
        self::assertNull($directory->fuzzyCatalogStatistics());
        $directory->findCandidates($this->query('VIA E. FERMI', 'TORINO', 'TO'));
        $statistics = $directory->fuzzyCatalogStatistics();

        self::assertNotNull($statistics);
        self::assertSame(12, $statistics->sourceLogicalNames);
        self::assertSame(11, $statistics->includedSourceNames);
        self::assertSame(1, $statistics->excludedSourceNames);
        self::assertSame(['GALLERIA' => 1], $statistics->excludedByPrefix);
        self::assertSame(10, $statistics->canonicalLogicalNames);
        self::assertSame(100 * 11 / 12, $statistics->coveragePercentage());
    }

    public function testPersistentDatabaseDoesNotChangeWhenTempCatalogIsBuilt(): void
    {
        $before = hash_file('sha256', $this->database);
        $directory = new SqliteAddressDirectory($this->database);
        $directory->findCandidates($this->query('VIA E. FERMI', 'TORINO', 'TO'));
        unset($directory);

        self::assertSame($before, hash_file('sha256', $this->database));
    }

    private function query(string $street, string $city, string $province): FuzzyStreetCandidateQuery
    {
        $tokenized = (new StreetNameTokenizer())->tokenize($street);
        self::assertNotNull($tokenized);

        return new FuzzyStreetCandidateQuery($city, $province, $tokenized);
    }

    private function candidate(string $street): FuzzyStreetNameCandidate
    {
        $tokenized = (new StreetNameTokenizer())->tokenize($street);
        self::assertNotNull($tokenized);

        return new FuzzyStreetNameCandidate($tokenized);
    }

    /** @param list<FuzzyStreetNameCandidate> $candidates @return list<string> */
    private function candidateNames(array $candidates): array
    {
        return array_map(static fn (FuzzyStreetNameCandidate $candidate): string => $candidate->streetName->canonicalName, $candidates);
    }

    private function connection(SqliteAddressDirectory $directory): PDO
    {
        return (new \ReflectionProperty(SqliteAddressDirectory::class, 'pdo'))->getValue($directory);
    }

    private function tempObjectCount(PDO $pdo, string $pattern): int
    {
        $statement = $pdo->prepare("SELECT COUNT(*) FROM sqlite_temp_master WHERE name GLOB :pattern");
        $statement->execute([':pattern' => $pattern]);

        return (int) $statement->fetchColumn();
    }
}
