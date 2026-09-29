<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Directory;

use Normalizzatore\Directory\DbfReader;
use Normalizzatore\Directory\DbfReaderException;
use Normalizzatore\Tests\Support\DbfFixture;
use PHPUnit\Framework\TestCase;

final class DbfReaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/normalizzatore-dbf-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testReadsFieldsPreservingStringsPaddingAndUtf8(): void
    {
        $path = $this->directory . '/fixture.dbf';
        DbfFixture::write($path, DbfFixture::rows());

        $reader = new DbfReader($path);
        $records = iterator_to_array($reader->records(), false);

        self::assertSame(8, $reader->declaredRecordCount());
        self::assertSame(0x03, $reader->dbfVersion());
        self::assertSame(1, $reader->deletedRecordCount());
        self::assertCount(7, $records);
        self::assertSame('VIA CITTÀ', $records[0]->vianum);
        self::assertSame('00165', $records[0]->cap);
        self::assertSame('ROMA', $records[0]->citta);
        self::assertSame('RM', $records[0]->pr);
        self::assertSame('D', $records[0]->pariDispa);
        self::assertSame('1', $records[0]->civicoDa);
        self::assertSame('19', $records[0]->civicoA);
        self::assertSame('DISUS', $records[1]->cap);
        self::assertSame('X', $records[2]->cap);
        self::assertSame('', $records[3]->cap);
        self::assertSame('KM', $records[3]->pariDispa);
        self::assertSame('62,000', $records[3]->civicoDa);
        self::assertSame('R', $records[4]->pariDispa);
        self::assertSame('4/A', $records[4]->civicoDa);
        self::assertSame('38A', $records[4]->civicoA);
        self::assertSame('km', $records[5]->pariDispa);
        self::assertSame('5 R', $records[5]->civicoDa);
        self::assertSame('3/4', $records[5]->civicoA);
        self::assertEquals($records[0], $records[6]);
    }

    public function testRejectsTruncatedAndInvalidHeadersAndTruncatedRecords(): void
    {
        $truncatedHeader = $this->directory . '/short.dbf';
        file_put_contents($truncatedHeader, 'short');
        try {
            new DbfReader($truncatedHeader);
            self::fail('Expected truncated header exception.');
        } catch (DbfReaderException $exception) {
            self::assertStringContainsString('truncated', $exception->getMessage());
        }

        $invalidHeader = $this->directory . '/invalid.dbf';
        file_put_contents($invalidHeader, str_repeat("\0", 32));
        try {
            new DbfReader($invalidHeader);
            self::fail('Expected invalid version exception.');
        } catch (DbfReaderException $exception) {
            self::assertStringContainsString('Unsupported DBF version', $exception->getMessage());
        }

        $truncatedRecord = $this->directory . '/record.dbf';
        DbfFixture::write($truncatedRecord, array_slice(DbfFixture::rows(), 0, 1));
        $contents = file_get_contents($truncatedRecord);
        file_put_contents($truncatedRecord, substr($contents, 0, -2));
        $reader = new DbfReader($truncatedRecord);
        try {
            iterator_to_array($reader->records(), false);
            self::fail('Expected truncated record exception.');
        } catch (DbfReaderException $exception) {
            self::assertStringContainsString('truncated', $exception->getMessage());
        }
    }
}
