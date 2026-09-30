<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Directory;

use Normalizzatore\Directory\DirectoryEntry;
use Normalizzatore\Directory\DirectorySchemaException;
use Normalizzatore\Directory\SqliteAddressDirectory;
use Normalizzatore\Directory\SqliteDirectoryImporter;
use Normalizzatore\Tests\Support\SqliteDirectoryFixture;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteAddressDirectoryTest extends TestCase
{
    private string $directory;
    private string $database;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/normalizzatore-directory-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->database = $this->directory . '/directory.sqlite';
        SqliteDirectoryFixture::create($this->database, [
            SqliteDirectoryFixture::row('CORSO CASTELFIDARDO', '10128', 'TORINO', 'TO', 'D', '1', '19'),
            SqliteDirectoryFixture::row('CORSO CASTELFIDARDO', '10129', 'TORINO', 'TO', 'D', '21', '30000'),
            SqliteDirectoryFixture::row('CORSO CASTELFIDARDO', '10128', 'TORINO', 'TO', 'P', '2', '20'),
            SqliteDirectoryFixture::row('CORSO CASTELFIDARDO', '10129', 'TORINO', 'TO', 'P', '22', '30000'),
            SqliteDirectoryFixture::row('VIA SANTI AUDIFACE ED ABACUC', '00100', 'ROMA', 'RM', 'T', '', ''),
            SqliteDirectoryFixture::row('PIAZZA ALDO MORO STATISTA', '94010', 'CATENUANUOVA', 'EN', 'T', '', ''),
            SqliteDirectoryFixture::row('VIA 11 SETTEMBRE 2001', '09024', 'MONASTIR', 'CA', 'T', '', ''),
            SqliteDirectoryFixture::row('VIA DUPLICATA', '00001', 'ROMA', 'RM', 'D', '1', '1'),
            SqliteDirectoryFixture::row('VIA DUPLICATA', '00001', 'ROMA', 'RM', 'D', '1', '1'),
            SqliteDirectoryFixture::row('VIA TERRITORIALE', '00100', 'ROMA', 'RM', 'T', '', ''),
            SqliteDirectoryFixture::row('VIA TERRITORIALE 2', '00100', 'ROMA', 'RM', 'T', '', ''),
            SqliteDirectoryFixture::row('VIA TERRITORIALE 3', 'X', '  roma  ', 'XX', 'T', '', ''),
            SqliteDirectoryFixture::row('VIA TERRITORIALE 4', '', 'Roma', 'YY', 'T', '', ''),
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function testFindsAllRowsCaseInsensitivelyAndInDeterministicOrder(): void
    {
        $directory = new SqliteAddressDirectory($this->database);
        $entries = $directory->findByStreetCityProvince('Corso Castelfidardo', 'Torino', 'to');

        self::assertCount(4, $entries);
        self::assertContainsOnlyInstancesOf(DirectoryEntry::class, $entries);
        self::assertSame([1, 2, 3, 4], array_map(static fn (DirectoryEntry $entry): int => $entry->id, $entries));
        self::assertSame(['10128', '10129', '10128', '10129'], array_map(static fn (DirectoryEntry $entry): string => $entry->cap, $entries));
        self::assertSame('CORSO CASTELFIDARDO', $entries[0]->vianum);
        self::assertSame('19', $entries[0]->civicoA);
    }

    public function testWhitespaceVariantsUseTheSameConservativeKey(): void
    {
        $entries = (new SqliteAddressDirectory($this->database))
            ->findByStreetCityProvince("  corso   castelfidardo\t", " Torino  ", " TO ");

        self::assertCount(4, $entries);
    }

    public function testReturnsAnEmptyListForAnUnmatchedStreet(): void
    {
        self::assertSame([], (new SqliteAddressDirectory($this->database))->findByStreetCityProvince('VIA ASSENTE', 'ROMA', 'RM'));
    }

    public function testDoesNotExpandAbbreviationsOrRemoveQualifiers(): void
    {
        $directory = new SqliteAddressDirectory($this->database);
        self::assertSame([], $directory->findByStreetCityProvince('Via S.S. Audiface ed Abacuc', 'Roma', 'RM'));
        self::assertSame([], $directory->findByStreetCityProvince('Piazza Aldo Moro', 'Catenanuova', 'EN'));
    }

    public function testProvinceIsAnExactPartOfTheLookupKey(): void
    {
        self::assertSame(
            [],
            (new SqliteAddressDirectory($this->database))->findByStreetCityProvince('Via 11 Settembre 2001', 'Monastir', 'SU'),
        );
    }

    public function testPreservesIdenticalDuplicateRows(): void
    {
        $entries = (new SqliteAddressDirectory($this->database))->findByStreetCityProvince('Via duplicata', 'Roma', 'rm');
        self::assertCount(2, $entries);
        self::assertNotSame($entries[0]->id, $entries[1]->id);
    }

    public function testFindsTerritorialGroupsByCityOnlyAndPreservesOriginalValues(): void
    {
        $entries = (new SqliteAddressDirectory($this->database))->findTerritorialEntries("  roma\t");

        self::assertCount(4, $entries);
        self::assertSame([
            ['  roma  ', 'XX', 'X', 1],
            ['ROMA', 'RM', '00001', 2],
            ['ROMA', 'RM', '00100', 3],
            ['Roma', 'YY', '', 1],
        ], array_map(static fn ($entry): array => [$entry->city, $entry->province, $entry->cap, $entry->recordCount], $entries));
    }

    public function testTerritorialLookupReturnsNoRowsForUnknownCity(): void
    {
        self::assertSame([], (new SqliteAddressDirectory($this->database))->findTerritorialEntries('CITTA ASSENTE'));
    }

    public function testStreetOnlyLookupDoesNotPrepareTerritorialTemporaryIndex(): void
    {
        $directory = new SqliteAddressDirectory($this->database);
        $property = new \ReflectionProperty(SqliteAddressDirectory::class, 'pdo');
        $pdo = $property->getValue($directory);

        self::assertNotEmpty($directory->findByStreetCityProvince('Corso Castelfidardo', 'Torino', 'TO'));
        self::assertSame(0, (int) $pdo->query(
            "SELECT COUNT(*) FROM sqlite_temp_master WHERE name = 'territorial_directory_entries'",
        )->fetchColumn());
    }

    public function testTerritorialTemporaryIndexDoesNotChangePersistentDatabase(): void
    {
        $before = hash_file('sha256', $this->database);
        $directory = new SqliteAddressDirectory($this->database);
        $directory->findTerritorialEntries('Roma');
        $directory->findTerritorialEntries('Torino');
        unset($directory);

        self::assertSame($before, hash_file('sha256', $this->database));

        $pdo = new PDO('sqlite:' . $this->database);
        self::assertSame(
            ['idx_directory_entries_lookup_key'],
            $pdo->query("PRAGMA index_list('directory_entries')")->fetchAll(PDO::FETCH_COLUMN, 1),
        );
    }

    public function testFailedTemporaryIndexPreparationRollsBackAndCanBeRetried(): void
    {
        $directory = new SqliteAddressDirectory($this->database);
        $property = new \ReflectionProperty(SqliteAddressDirectory::class, 'pdo');
        $pdo = $property->getValue($directory);
        $pdo->exec('PRAGMA query_only = OFF');
        $pdo->exec('CREATE TEMP TABLE conflicting_index_owner (value TEXT)');
        $pdo->exec('CREATE INDEX temp.idx_territorial_directory_city_key ON conflicting_index_owner (value)');
        $pdo->exec('PRAGMA query_only = ON');

        try {
            $directory->findTerritorialEntries('Roma');
            self::fail('Expected temporary index preparation to fail on the conflicting index name.');
        } catch (DirectorySchemaException $exception) {
            self::assertSame('Unable to build the temporary territorial lookup index.', $exception->getMessage());
        }

        self::assertFalse($pdo->query(
            "SELECT 1 FROM sqlite_temp_master WHERE type = 'table' AND name = 'territorial_directory_entries'",
        )->fetchColumn());

        $pdo->exec('PRAGMA query_only = OFF');
        $pdo->exec('DROP INDEX temp.idx_territorial_directory_city_key');
        $pdo->exec('DROP TABLE temp.conflicting_index_owner');
        $pdo->exec('PRAGMA query_only = ON');

        self::assertNotEmpty($directory->findTerritorialEntries('Roma'));
    }

    public function testRejectsIncompatibleSchemaWithARebuildHint(): void
    {
        $oldPath = $this->directory . '/old.sqlite';
        $pdo = new PDO('sqlite:' . $oldPath);
        $pdo->exec('CREATE TABLE directory_metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->exec("INSERT INTO directory_metadata VALUES ('schema_version', '1')");
        unset($pdo);

        $this->expectException(DirectorySchemaException::class);
        $this->expectExceptionMessage('Rebuild it with bin/import-directory');
        new SqliteAddressDirectory($oldPath);
    }

    public function testCurrentSchemaVersionIsTwo(): void
    {
        self::assertSame('2', SqliteDirectoryImporter::SCHEMA_VERSION);
    }
}
