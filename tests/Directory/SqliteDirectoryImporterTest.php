<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Directory;

use Normalizzatore\Directory\SqliteDirectoryImporter;
use Normalizzatore\Tests\Support\DbfFixture;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteDirectoryImporterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('pdo_sqlite extension is not available.');
        }
        $this->directory = sys_get_temp_dir() . '/normalizzatore-import-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    public function testImportsStreamingRecordsMetadataIndexAndDuplicates(): void
    {
        $dbfPath = $this->directory . '/source.dbf';
        $sqlitePath = $this->directory . '/nested/directory.sqlite';
        $rows = DbfFixture::rows();
        $rows[1]['vianum'] = 'Via   disus';
        $rows[1]['citta'] = 'Roma   Centro';
        $rows[1]['pr'] = 'rm';
        DbfFixture::write($dbfPath, $rows);

        $count = (new SqliteDirectoryImporter())->import($dbfPath, $sqlitePath);
        self::assertSame(7, $count);
        self::assertFileExists($sqlitePath);

        $pdo = new PDO('sqlite:' . $sqlitePath, options: [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        self::assertSame(7, (int) $pdo->query('SELECT COUNT(*) FROM directory_entries')->fetchColumn());
        self::assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM directory_entries WHERE vianum = 'VIA CITTÀ'")->fetchColumn());
        self::assertSame('00165', $pdo->query('SELECT cap FROM directory_entries WHERE id = 1')->fetchColumn());
        self::assertSame('', $pdo->query('SELECT cap FROM directory_entries WHERE id = 4')->fetchColumn());
        self::assertSame('VIA CITTÀ', $pdo->query('SELECT vianum FROM directory_entries WHERE id = 1')->fetchColumn());
        self::assertSame("VIA CITTA'", $pdo->query('SELECT vianum_key FROM directory_entries WHERE id = 1')->fetchColumn());
        self::assertSame('ROMA', $pdo->query('SELECT citta_key FROM directory_entries WHERE id = 1')->fetchColumn());
        self::assertSame('RM', $pdo->query('SELECT pr_key FROM directory_entries WHERE id = 1')->fetchColumn());
        self::assertSame('Via   disus', $pdo->query('SELECT vianum FROM directory_entries WHERE id = 2')->fetchColumn());
        self::assertSame('VIA DISUS', $pdo->query('SELECT vianum_key FROM directory_entries WHERE id = 2')->fetchColumn());
        self::assertSame('Roma   Centro', $pdo->query('SELECT citta FROM directory_entries WHERE id = 2')->fetchColumn());
        self::assertSame('ROMA CENTRO', $pdo->query('SELECT citta_key FROM directory_entries WHERE id = 2')->fetchColumn());
        self::assertSame('rm', $pdo->query('SELECT pr FROM directory_entries WHERE id = 2')->fetchColumn());
        self::assertSame('RM', $pdo->query('SELECT pr_key FROM directory_entries WHERE id = 2')->fetchColumn());

        $metadata = $pdo->query('SELECT key, value FROM directory_metadata')->fetchAll(PDO::FETCH_KEY_PAIR);
        self::assertSame(SqliteDirectoryImporter::SCHEMA_VERSION, $metadata['schema_version']);
        self::assertSame('7', $metadata['record_count']);
        self::assertSame('8', $metadata['source_record_count']);
        self::assertSame('1', $metadata['deleted_record_count']);
        self::assertSame('CP850', $metadata['source_encoding']);
        self::assertSame('0x03', $metadata['dbf_version']);
        self::assertSame(hash_file('sha256', $dbfPath), $metadata['source_sha256']);

        $indexes = $pdo->query("PRAGMA index_list('directory_entries')")->fetchAll();
        self::assertContains('idx_directory_entries_lookup_key', array_column($indexes, 'name'));
        self::assertNotContains('idx_directory_entries_lookup', array_column($indexes, 'name'));
        $indexColumns = $pdo->query("PRAGMA index_info('idx_directory_entries_lookup_key')")->fetchAll();
        self::assertSame(['vianum_key', 'citta_key', 'pr_key'], array_column($indexColumns, 'name'));
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
