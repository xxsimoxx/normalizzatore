<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Csv;

use Normalizzatore\Address\AddressParser;
use Normalizzatore\Address\AddressStrategyClassifier;
use Normalizzatore\Application\AddressProcessor;
use Normalizzatore\City\CapizzatedCity;
use Normalizzatore\City\CapizzatedCityCatalog;
use Normalizzatore\Csv\AddressProcessingResultSerializer;
use Normalizzatore\Csv\CsvNormalizationPipeline;
use Normalizzatore\Csv\CsvReader;
use Normalizzatore\Csv\CsvWriter;
use Normalizzatore\Csv\NormalizeArguments;
use Normalizzatore\Directory\SqliteAddressDirectory;
use Normalizzatore\Normalization\AddressFieldNormalizer;
use Normalizzatore\Resolution\AddressResolutionOrchestrator;
use Normalizzatore\Resolution\CapResolver;
use Normalizzatore\Resolution\TerritorialResolver;
use Normalizzatore\Tests\Support\SqliteDirectoryFixture;
use Normalizzatore\Verification\SourceCapVerifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CsvNormalizationPipelineTest extends TestCase
{
    private string $directory;
    private string $database;
    private CsvNormalizationPipeline $pipeline;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/normalizzatore-pipeline-' . bin2hex(random_bytes(5));
        mkdir($this->directory);
        $this->database = $this->directory . '/directory.sqlite';
        SqliteDirectoryFixture::create($this->database, [
            SqliteDirectoryFixture::row('VIA ROMA', '00100', 'ROMA', 'RM', 'T', '1', '99'),
            SqliteDirectoryFixture::row('VIA A "B" | C: D->E', '00100', 'ROMA', 'RM', 'T', '1', '99'),
            SqliteDirectoryFixture::row('OLBIA', '07026', 'OLBIA', 'SS', '', '', ''),
            SqliteDirectoryFixture::row('CASTRO', '24063', 'CASTRO', 'BG', '', '', ''),
            SqliteDirectoryFixture::row('CASTRO', '73030', 'CASTRO', 'LE', '', '', ''),
            SqliteDirectoryFixture::row("VIA LOCALITA` LE SALINE", '08020', 'Torpè', 'NU', 'T', '', ''),
        ]);
        $directory = new SqliteAddressDirectory($this->database);
        $orchestrator = new AddressResolutionOrchestrator(
            new AddressStrategyClassifier(new CapizzatedCityCatalog([new CapizzatedCity('ROMA', 'RM')])),
            new AddressParser(),
            $directory,
            new CapResolver(),
            new TerritorialResolver(),
        );
        $processor = new AddressProcessor($orchestrator, new SourceCapVerifier(), new AddressFieldNormalizer());
        $this->pipeline = new CsvNormalizationPipeline(new CsvReader(), new CsvWriter(), $processor, new AddressProcessingResultSerializer());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        foreach (glob($this->directory . '/.*') ?: [] as $path) {
            if (basename($path) !== '.' && basename($path) !== '..') {
                unlink($path);
            }
        }
        rmdir($this->directory);
    }

    public function testStreamsDefaultSemicolonCsvPreservingSourceAndAppendingContractColumns(): void
    {
        $input = $this->write('input.csv', "note;CAP;vianum;citta;Provincia\nkeep;00100;VIA ROMA 50;Roma;RM\nkeep2;99999;VIA ROMA 15;Roma;RM\n");
        $output = $this->directory . '/output.csv';

        $summary = $this->pipeline->run($input, $output);
        $document = (new CsvReader())->read($output, ';');

        self::assertSame(['note', 'CAP', 'vianum', 'citta', 'Provincia', ...AddressProcessingResultSerializer::OUTPUT_COLUMNS], $document->header);
        self::assertSame(['keep', '00100', 'VIA ROMA 50', 'Roma', 'RM'], array_slice($document->rows[0], 0, 5));
        self::assertSame('VIA ROMA', $document->rows[0][5]);
        self::assertSame('50', $document->rows[0][6]);
        self::assertSame('00100', $document->rows[0][8]);
        self::assertSame('RESOLVED', $document->rows[0][11]);
        self::assertSame('MATCH', $document->rows[0][12]);
        self::assertSame('00100', $document->rows[1][8]);
        self::assertSame('MISMATCH', $document->rows[1][12]);
        self::assertStringContainsString('CAP:"99999"->"00100":source_cap_mismatch', $document->rows[1][13]);
        self::assertSame(2, $summary->processed);
        self::assertSame(2, $summary->resolved);
        self::assertSame(1, $summary->rowsWithCorrections);
    }

    public function testCustomCommaDelimiterIsUsedForReadingAndWriting(): void
    {
        $input = $this->write('comma.csv', "vianum,CAP,citta,Provincia,extra\nVIA ROMA 15,00100,ROMA,RM,\"a,b\"\n");
        $output = $this->directory . '/comma-output.csv';

        $this->pipeline->run($input, $output, ',');
        $document = (new CsvReader())->read($output, ',');

        self::assertSame('a,b', $document->rows[0][4]);
        self::assertSame('RESOLVED', $document->rows[0][count($document->header) - 4]);
    }

    public function testCanonicalizesStreetAndCityOutputButPreservesTheirSourceColumns(): void
    {
        $input = $this->write('orthographic.csv', "vianum;CAP;citta;Provincia\nVIA LOCALITA` LE SALINE;08020;Torpè;NU\n");
        $output = $this->directory . '/orthographic-output.csv';

        $this->pipeline->run($input, $output);
        $row = (new CsvReader())->read($output, ';')->rows[0];

        self::assertSame("VIA LOCALITA` LE SALINE", $row[0]);
        self::assertSame('Torpè', $row[2]);
        self::assertSame("VIA LOCALITA' LE SALINE", $row[4]);
        self::assertSame("TORPE'", $row[8]);
        self::assertSame('', $row[12]);
    }

    public function testNoMatchLeavesNormalizedCapEmptyRatherThanFallingBackToSource(): void
    {
        $input = $this->write('no-match.csv', "vianum;CAP;citta;Provincia\nVia sconosciuta 1;07026;DORGALI;NU\n");
        $output = $this->directory . '/no-match-output.csv';

        $this->pipeline->run($input, $output);
        $row = (new CsvReader())->read($output, ';')->rows[0];

        self::assertSame('07026', $row[1]);
        self::assertSame('', $row[7]);
        self::assertSame('NO_MATCH', $row[10]);
        self::assertSame('UNVERIFIABLE', $row[11]);
    }

    public function testWritesOnlyDeterminedFieldsAndSerializesTypedFieldCorrection(): void
    {
        $input = $this->write('field-states.csv', "vianum;CAP;citta;Provincia\nvia roma 15;99999; roma ;rm\nVIA INESISTENTE 4;00100;ROMA;RM\n; ; ;\nVIA SARDEGNA 12/B 15;07026;OLBIA;SS\n;07026; OLBIA ;SS\nVIA ROMA 50;07026;OLBIA;SS\n");
        $output = $this->directory . '/field-states-output.csv';

        $summary = $this->pipeline->run($input, $output);
        $rows = (new CsvReader())->read($output, ';')->rows;

        self::assertSame('VIA ROMA', $rows[0][4]);
        self::assertSame('15', $rows[0][5]);
        self::assertSame('00100', $rows[0][7]);
        self::assertSame('ROMA', $rows[0][8]);
        self::assertSame('RESOLVED', $rows[0][10]);
        self::assertSame('MISMATCH', $rows[0][11]);
        self::assertStringContainsString('CAP:"99999"->"00100":source_cap_mismatch', $rows[0][12]);
        self::assertStringNotContainsString('VIA:', $rows[0][12]);

        self::assertSame('VIA INESISTENTE', $rows[1][4]);
        self::assertSame('4', $rows[1][5]);
        self::assertSame('', $rows[1][8]);
        self::assertSame('', $rows[2][4]);
        self::assertSame('', $rows[2][5]);
        self::assertSame('', $rows[2][8]);
        self::assertSame('', $rows[3][4]);
        self::assertSame('', $rows[3][5]);
        self::assertSame('RESOLVED', $rows[3][10]);
        self::assertStringContainsString('multiple_parser_interpretations', $rows[3][13]);
        self::assertSame('OLBIA', $rows[4][8]);
        self::assertSame('RESOLVED', $rows[4][10]);
        self::assertStringContainsString('CITTA:" OLBIA "->"OLBIA":whitespace_normalization', $rows[4][12]);
        self::assertSame('VIA ROMA', $rows[5][4]);
        self::assertSame('50', $rows[5][5]);
        self::assertSame('', $rows[5][12]);
        self::assertSame(2, $summary->rowsWithCorrections);
    }

    public function testEscapesCorrectionValuesThatCouldCollideWithInternalSeparators(): void
    {
        $input = $this->write('escaping.csv', "vianum;CAP;citta;Provincia\nvia a \"b\" | c: d->e 15;99999;ROMA;RM\n");
        $output = $this->directory . '/escaping-output.csv';

        $this->pipeline->run($input, $output);
        $row = (new CsvReader())->read($output, ';')->rows[0];

        self::assertSame('CAP:"99999"->"00100":source_cap_mismatch', $row[12]);
    }

    public function testAmbiguousTerritorialResultKeepsNormalizedCapEmpty(): void
    {
        $input = $this->write('ambiguous.csv', "vianum;CAP;citta;Provincia\nVia qualunque 1;24063;CASTRO;BG\n");
        $output = $this->directory . '/ambiguous-output.csv';

        $this->pipeline->run($input, $output);
        $row = (new CsvReader())->read($output, ';')->rows[0];

        self::assertSame('', $row[7]);
        self::assertSame('AMBIGUOUS', $row[10]);
        self::assertSame('UNVERIFIABLE', $row[11]);
    }

    public function testRejectsMissingUnreadableInputAndDoesNotCreateOutput(): void
    {
        $output = $this->directory . '/absent-output.csv';
        try {
            $this->pipeline->run($this->directory . '/missing.csv', $output);
            self::fail('Expected unreadable input to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('readable file', $exception->getMessage());
        }
        self::assertFileDoesNotExist($output);

        try {
            $this->pipeline->run($this->directory, $output);
            self::fail('Expected a directory input to fail.');
        } catch (RuntimeException) {
            self::assertFileDoesNotExist($output);
        }
    }

    public function testRejectsUnreadableInputWhenTheRuntimeCanRepresentIt(): void
    {
        $input = $this->write('unreadable.csv', "vianum;CAP;citta;Provincia\n\n");
        chmod($input, 0000);
        if (is_readable($input)) {
            chmod($input, 0600);
            self::markTestSkipped('The current user can read mode-000 files, so unreadability is not observable here.');
        }
        try {
            $this->pipeline->run($input, $this->directory . '/unreadable-output.csv');
            self::fail('Expected unreadable input to fail.');
        } catch (RuntimeException) {
            self::assertFileDoesNotExist($this->directory . '/unreadable-output.csv');
        } finally {
            chmod($input, 0600);
        }
    }

    public function testRejectsExistingOutputWithoutChangingIt(): void
    {
        $input = $this->write('existing-input.csv', "vianum;CAP;citta;Provincia\n; ;;;\n");
        $output = $this->write('existing.csv', 'preserve-me');

        try {
            $this->pipeline->run($input, $output);
            self::fail('Expected existing output to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('already exists', $exception->getMessage());
        }
        self::assertSame('preserve-me', file_get_contents($output));
    }

    public function testRejectsMissingRequiredHeaderAndReservedHeaderBeforeOutputPublication(): void
    {
        foreach ([
            "vianum;CAP;citta\n; ; ;\n",
            "vianum;CAP;citta;Provincia;via_normalizzata\n; ; ; ;old\n",
        ] as $index => $csv) {
            $input = $this->write('bad-header-' . $index . '.csv', $csv);
            $output = $this->directory . '/bad-header-' . $index . '-out.csv';
            try {
                $this->pipeline->run($input, $output);
                self::fail('Expected invalid header to fail.');
            } catch (RuntimeException) {
                self::assertFileDoesNotExist($output);
            }
        }
    }

    public function testMalformedRowCleansTemporaryFileAndLeavesNoPartialOutput(): void
    {
        $input = $this->write('malformed.csv', "vianum;CAP;citta;Provincia;extra\nVIA ROMA 15;00100;ROMA;RM;ok\nshort;row\n");
        $output = $this->directory . '/malformed-output.csv';

        try {
            $this->pipeline->run($input, $output);
            self::fail('Expected malformed row to fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Malformed CSV row', $exception->getMessage());
        }
        self::assertFileDoesNotExist($output);
        self::assertSame([], glob($this->directory . '/.malformed-output.csv.tmp-*'));
    }

    public function testArgumentParserProvidesDefaultAndCustomDelimiterOnly(): void
    {
        self::assertSame(';', NormalizeArguments::parse(['in.csv', 'out.csv'])->delimiter);
        self::assertSame(',', NormalizeArguments::parse(['in.csv', 'out.csv', '--delimiter=,'])->delimiter);
        self::assertSame("\t", NormalizeArguments::parse(['in.csv', 'out.csv', "--delimiter=\t"])->delimiter);
        $this->expectException(\InvalidArgumentException::class);
        NormalizeArguments::parse(['in.csv', 'out.csv', '--force']);
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, $contents);
        return $path;
    }
}
