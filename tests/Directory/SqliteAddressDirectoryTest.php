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
