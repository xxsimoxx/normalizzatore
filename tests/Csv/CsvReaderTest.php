<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Csv;

use InvalidArgumentException;
use Normalizzatore\Csv\CsvReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CsvReaderTest extends TestCase
{
    private CsvReader $reader;

    protected function setUp(): void
    {
        $this->reader = new CsvReader();
    }

    public function testReadsSemicolonDelimitedCsvAndMapsColumnsByHeaderName(): void
    {
        $document = $this->reader->read($this->fixture('semicolon.csv'), ';');

        self::assertSame(['vianum', 'extra', 'Provincia', 'CAP', 'citta'], $document->header);
        self::assertSame([['Via Roma', 'keep this', 'RM', '00100', 'Roma']], $document->rows);
        self::assertSame(0, $document->columnIndex('vianum'));
        self::assertSame(3, $document->columnIndex('CAP'));
        self::assertSame(4, $document->columnIndex('citta'));
        self::assertSame(2, $document->columnIndex('Provincia'));
    }

    public function testReadsCommaDelimitedCsvAndPreservesDelimiterInsideQuotedField(): void
    {
        $document = $this->reader->read($this->fixture('comma.csv'), ',');

        self::assertSame(['Via Roma, Centro', '00100', 'Roma', 'RM', 'untouched'], $document->rows[0]);
    }

    public function testReadsTabDelimitedCsv(): void
    {
        $document = $this->reader->read($this->fixture('tab.tsv'), "\t");

        self::assertSame(['value', '00100', 'Via Roma', 'Roma', 'RM'], $document->rows[0]);
    }

    public function testPreservesEmptyFieldsAndAdditionalColumns(): void
    {
        $document = $this->readContent("extra;vianum;CAP;citta;Provincia;note\nkeep;;00123;;;unchanged\n", ';');

        self::assertSame(['keep', '', '00123', '', '', 'unchanged'], $document->rows[0]);
        self::assertSame('00123', $document->rows[0][$document->columnIndex('CAP')]);
    }

    public function testUnescapesDoubledQuotesInsideQuotedFields(): void
    {
        $document = $this->readContent("vianum;CAP;citta;Provincia;extra\n\"Via \"\"Nuova\"\"\";00100;Roma;RM;\"nota \"\"speciale\"\"\"\n", ';');

        self::assertSame('Via "Nuova"', $document->rows[0][0]);
        self::assertSame('nota "speciale"', $document->rows[0][4]);
    }

    public function testRemovesOptionalUtf8BomFromFirstHeader(): void
    {
        $document = $this->readContent("\xEF\xBB\xBFvianum,CAP,citta,Provincia\nVia Roma,00100,Roma,RM\n");

        self::assertSame(['vianum', 'CAP', 'citta', 'Provincia'], $document->header);
        self::assertSame(1, $document->columnIndex('CAP'));
    }

    public function testPreservesUtf8ValuesAndCapLeadingZeroes(): void
    {
        $document = $this->readContent("vianum,CAP,citta,Provincia\nVia Garibaldi,00123,Città,RM\n");

        self::assertSame('Città', $document->rows[0][2]);
        self::assertSame('00123', $document->rows[0][1]);
        self::assertIsString($document->rows[0][1]);
    }

    public function testHeaderOnlyCsvHasNoDataRows(): void
    {
        $document = $this->readContent("vianum,CAP,citta,Provincia\n");

        self::assertSame(['vianum', 'CAP', 'citta', 'Provincia'], $document->header);
        self::assertSame([], $document->rows);
    }

    public function testEmptyCsvThrowsAnExceptionBecauseItHasNoHeader(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no header row');

        $this->readContent('');
    }

    public function testRejectsUnsupportedDelimiter(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->reader->read($this->fixture('comma.csv'), '|');
    }

    private function fixture(string $name): string
    {
        return __DIR__ . '/../Fixtures/' . $name;
    }

    private function readContent(string $content, string $delimiter = ','): \Normalizzatore\Csv\CsvDocument
    {
        $path = tempnam(sys_get_temp_dir(), 'normalizzatore-csv-');
        self::assertNotFalse($path);

        try {
            self::assertSame(strlen($content), file_put_contents($path, $content));

            return $this->reader->read($path, $delimiter);
        } finally {
            unlink($path);
        }
    }
}
