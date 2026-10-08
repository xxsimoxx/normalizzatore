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
use Normalizzatore\Directory\AddressDirectoryInterface;
use Normalizzatore\Directory\FuzzyStreetCandidateProvider;
use Normalizzatore\Directory\FuzzyStreetCandidateSet;
use Normalizzatore\Directory\SqliteAddressDirectory;
use Normalizzatore\Normalization\AddressFieldNormalizer;
use Normalizzatore\Resolution\AddressResolutionOrchestrator;
use Normalizzatore\Resolution\CapResolver;
use Normalizzatore\Resolution\FuzzyStreetCandidateQuery;
use Normalizzatore\Resolution\FuzzyStreetNameCandidate;
use Normalizzatore\Resolution\FuzzyStreetMatcher;
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
            SqliteDirectoryFixture::row('VIA ENRICO FERMI', '00100', 'ROMA', 'RM', 'T', '1', '10'),
            SqliteDirectoryFixture::row('VIA CAPPUCCINA', '00100', 'ROMA', 'RM', 'T', '1', '10'),
            SqliteDirectoryFixture::row('VIA GAETA', '00100', 'ROMA', 'RM', 'T', '1', '99'),
            SqliteDirectoryFixture::row('VIA ZATTA', '00200', 'ROMA', 'RM', 'T', '1', '99'),
            SqliteDirectoryFixture::row('VIA A "B" | C: D->E', '00100', 'ROMA', 'RM', 'T', '1', '99'),
            SqliteDirectoryFixture::row('OLBIA', '07026', 'OLBIA', 'SS', '', '', ''),
            SqliteDirectoryFixture::row('CASTRO', '24063', 'CASTRO', 'BG', '', '', ''),
            SqliteDirectoryFixture::row('CASTRO', '73030', 'CASTRO', 'LE', '', '', ''),
            SqliteDirectoryFixture::row("VIA LOCALITA` LE SALINE", '08020', 'Torpè', 'NU', 'T', '', ''),
        ]);
        $directory = new SqliteAddressDirectory($this->database);
        $fractionCatalogPath = $this->directory . '/frazioni.tsv';
        file_put_contents($fractionCatalogPath, "CAP\tCOMUNE\tFRAZIONE\tPROVINCIA\tTIPO\n00100\tROMA\tBorgata Nova\tRM\tNucleo abitato\n");
        $orchestrator = new AddressResolutionOrchestrator(
            new AddressStrategyClassifier(new CapizzatedCityCatalog([new CapizzatedCity('ROMA', 'RM')])),
            new AddressParser(),
            $directory,
            new CapResolver(),
            new TerritorialResolver(),
            $directory,
            new FuzzyStreetMatcher(),
            frazioneCatalog: new \Normalizzatore\Frazione\FrazioneCatalog($fractionCatalogPath),
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
        $default = NormalizeArguments::parse(['in.csv', 'out.csv']);
        self::assertSame(';', $default->delimiter);
        self::assertFalse($default->fuzzy);
        self::assertFalse($default->frazioni);
        self::assertSame(',', NormalizeArguments::parse(['in.csv', 'out.csv', '--delimiter=,'])->delimiter);
        self::assertSame("\t", NormalizeArguments::parse(['in.csv', 'out.csv', "--delimiter=\t"])->delimiter);
        $fuzzyFirst = NormalizeArguments::parse(['in.csv', 'out.csv', '--fuzzy', '--delimiter=,']);
        self::assertTrue($fuzzyFirst->fuzzy);
        self::assertSame(',', $fuzzyFirst->delimiter);
        $fuzzyLast = NormalizeArguments::parse(['in.csv', 'out.csv', '--delimiter=,', '--fuzzy']);
        self::assertTrue($fuzzyLast->fuzzy);
        self::assertSame(',', $fuzzyLast->delimiter);
        $fractionsBoth = NormalizeArguments::parse(['in.csv', 'out.csv', '--fuzzy', '--frazioni']);
        self::assertTrue($fractionsBoth->fuzzy);
        self::assertTrue($fractionsBoth->frazioni);
        $this->expectException(\InvalidArgumentException::class);
        NormalizeArguments::parse(['in.csv', 'out.csv', '--force']);
    }

    public function testArgumentParserRejectsFuzzyValuesAndDuplicateOptions(): void
    {
        foreach ([
            ['in.csv', 'out.csv', '--fuzzy=true'],
            ['in.csv', 'out.csv', '--fuzzy', '--fuzzy'],
            ['in.csv', 'out.csv', '--frazioni=true'],
            ['in.csv', 'out.csv', '--frazioni', '--frazioni'],
            ['in.csv', 'out.csv', '--delimiter=;', '--delimiter=;'],
        ] as $arguments) {
            try {
                NormalizeArguments::parse($arguments);
                self::fail('Expected unsupported or duplicate option to fail.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testFrazioneOptionPreservesSourceColumnsAndAddsNoOutputColumns(): void
    {
        $input = $this->write('fractions.csv', "note;vianum;CAP;citta;Provincia\nkeep;VIA ROMA 5;00100;Borgata Nova;RM\n");
        $output = $this->directory . '/fractions-output.csv';
        $summary = $this->pipeline->run($input, $output, ';', false, true);
        $document = (new CsvReader())->read($output, ';');
        $row = $document->rows[0];

        self::assertSame(['note', 'vianum', 'CAP', 'citta', 'Provincia', ...AddressProcessingResultSerializer::OUTPUT_COLUMNS], $document->header);
        self::assertSame(['keep', 'VIA ROMA 5', '00100', 'Borgata Nova', 'RM'], array_slice($row, 0, 5));
        self::assertSame('ROMA', $row[9]);
        self::assertStringContainsString('frazione_to_comune', $row[13]);
        self::assertStringContainsString('Nucleo abitato', $row[14]);
        self::assertSame(1, $summary->frazioneStatistics?->counts()['Nucleo abitato']['applied']);
    }

    public function testFuzzyCsvPipelineUsesTypedEvidenceAndAddsOnlySummaryStatistics(): void
    {
        $input = $this->write('fuzzy.csv', "note,vianum,CAP,citta,Provincia\nexact,VIA ROMA 5,00100,ROMA,RM\nabbreviation,VIA E. FERMI 5,00100,ROMA,RM\ntypo,VIA CAPUCCINA 5,00100,ROMA,RM\nambiguous,VIA GATTA 5,00100,ROMA,RM\nnominal-only,VIA CAPUCCINA 99,00100,ROMA,RM\nno-match,VIA SCONOSCIUTA 1,00100,ROMA,RM\nshort-token,VIA XYZ 5,00100,ROMA,RM\nprovince-conflict,VIA CAPUCCINA 5,00100,ROMA,XX\nparser,VIA 4 NOVEMBRE 1470,00100,ROMA,RM\nterritorial,VIA ROMA 5,07026,OLBIA,SS\n");
        $output = $this->directory . '/fuzzy-output.csv';

        $summary = $this->pipeline->run($input, $output, ',', true);
        $document = (new CsvReader())->read($output, ',');

        self::assertSame(['note', 'vianum', 'CAP', 'citta', 'Provincia', ...AddressProcessingResultSerializer::OUTPUT_COLUMNS], $document->header);
        self::assertSame('exact', $document->rows[0][0]);
        self::assertSame('VIA ROMA 5', $document->rows[0][1]);
        self::assertSame('VIA ENRICO FERMI', $document->rows[1][5]);
        self::assertSame('RESOLVED', $document->rows[1][11]);
        self::assertStringContainsString('fuzzy_abbreviation_expansion', $document->rows[1][13]);
        self::assertSame('VIA CAPPUCCINA', $document->rows[2][5]);
        self::assertSame('RESOLVED', $document->rows[2][11]);
        self::assertStringContainsString('fuzzy_typo_match', $document->rows[2][14]);
        self::assertSame('NO_MATCH', $document->rows[3][11]);
        self::assertStringContainsString('fuzzy_ambiguous', $document->rows[3][14]);
        self::assertSame('NO_MATCH', $document->rows[4][11]);
        self::assertStringNotContainsString('fuzzy_typo_correction', $document->rows[4][13], 'A nominal match without a resolved CAP cannot become an applied street correction.');
        self::assertStringContainsString('fuzzy_typo_match', $document->rows[4][14]);
        self::assertSame('RESOLVED', $document->rows[7][11]);
        self::assertSame('RM', $document->rows[7][10]);
        self::assertStringContainsString('territorial_province_correction', $document->rows[7][13]);
        self::assertStringContainsString('fuzzy_typo_match', $document->rows[7][14]);
        self::assertSame('RESOLVED', $document->rows[9][11]);
        self::assertStringNotContainsString('fuzzy_', $document->rows[9][14]);
        self::assertCount(15, $document->header);
        self::assertSame(10, $summary->processed);
        self::assertSame(7, $summary->fuzzyStatistics?->providerCalls);
        self::assertSame(0, $summary->fuzzyStatistics?->providerGeographicNotApplicable);
        self::assertSame(1, $summary->fuzzyStatistics?->abbreviationMatches);
        self::assertSame(3, $summary->fuzzyStatistics?->typoMatches);
        self::assertSame(1, $summary->fuzzyStatistics?->ambiguous);
        self::assertSame(1, $summary->fuzzyStatistics?->noMatch);
        self::assertSame(1, $summary->fuzzyStatistics?->nominalNotApplicable);
        self::assertSame(3, $summary->fuzzyStatistics?->resolved);
        self::assertSame(
            $summary->fuzzyStatistics?->providerCalls,
            $summary->fuzzyStatistics?->providerGeographicNotApplicable
                + $summary->fuzzyStatistics?->abbreviationMatches
                + $summary->fuzzyStatistics?->typoMatches
                + $summary->fuzzyStatistics?->ambiguous
                + $summary->fuzzyStatistics?->noMatch
                + $summary->fuzzyStatistics?->nominalNotApplicable,
        );
        self::assertLessThanOrEqual(
            $summary->fuzzyStatistics?->abbreviationMatches + $summary->fuzzyStatistics?->typoMatches,
            $summary->fuzzyStatistics?->resolved,
        );
        self::assertStringContainsString("Fuzzy via:\n  Ricerche candidate (provider invocato): 7", $summary->toText());
        self::assertStringContainsString('Provider senza ambito geografico utilizzabile: 0', $summary->toText());
        self::assertStringContainsString('Matching nominale non applicabile: 1', $summary->toText());
        self::assertStringContainsString('Match nominali per abbreviazione: 1', $summary->toText());
        self::assertStringContainsString('Risolti dopo il resolver CAP: 3', $summary->toText());
        self::assertStringContainsString(
            "Fuzzy via:\n  Ricerche candidate (provider invocato): 7\n  Provider senza ambito geografico utilizzabile: 0\n  Match nominali per abbreviazione: 1\n  Match nominali per typo: 3\n  Ambigui nominali: 1\n  Nessun nome compatibile: 1\n  Matching nominale non applicabile: 1\n  Risolti dopo il resolver CAP: 3\n",
            $summary->toText(),
        );

        $deterministicOutput = $this->directory . '/deterministic-output.csv';
        $deterministicSummary = $this->pipeline->run($input, $deterministicOutput, ',', false);
        self::assertNull($deterministicSummary->fuzzyStatistics);
        self::assertStringNotContainsString('Fuzzy:', $deterministicSummary->toText());
    }

    public function testFuzzyFlagDoesNotInitializeCatalogForExactAndTerritorialOnlyCsv(): void
    {
        $input = $this->write('fuzzy-lazy.csv', "vianum,CAP,citta,Provincia\nVIA ROMA 5,00100,ROMA,RM\nOLBIA,07026,OLBIA,SS\n,,,\n");
        $output = $this->directory . '/fuzzy-lazy-output.csv';

        $this->pipeline->run($input, $output, ',', true);
        self::assertSame(0, (int) $this->connection()->query(
            "SELECT COUNT(*) FROM sqlite_temp_master WHERE type='table' AND name='fuzzy_street_names'",
        )->fetchColumn());
    }

    public function testFuzzyOperationalFailureDoesNotPublishPartialCsv(): void
    {
        $directory = new class implements AddressDirectoryInterface, FuzzyStreetCandidateProvider {
            public function findByStreetCityProvince(string $street, string $city, string $province): array
            {
                return [];
            }

            public function findTerritorialEntries(string $city): array
            {
                return [];
            }

            public function findCandidates(FuzzyStreetCandidateQuery $query): FuzzyStreetCandidateSet
            {
                throw new RuntimeException('fuzzy catalog initialization failed');
            }

            public function findEntries(FuzzyStreetCandidateSet $set, FuzzyStreetNameCandidate $candidate): array
            {
                return [];
            }
        };
        $orchestrator = new AddressResolutionOrchestrator(
            new AddressStrategyClassifier(new CapizzatedCityCatalog([new CapizzatedCity('ROMA', 'RM')])),
            new AddressParser(),
            $directory,
            new CapResolver(),
            new TerritorialResolver(),
            $directory,
            new FuzzyStreetMatcher(),
        );
        $processor = new AddressProcessor($orchestrator, new SourceCapVerifier(), new AddressFieldNormalizer());
        $pipeline = new CsvNormalizationPipeline(new CsvReader(), new CsvWriter(), $processor, new AddressProcessingResultSerializer());
        $input = $this->write('fuzzy-operational-error.csv', "vianum;CAP;citta;Provincia\nVIA SCONOSCIUTA 1;00100;ROMA;RM\n");
        $output = $this->directory . '/fuzzy-operational-error-output.csv';

        try {
            $pipeline->run($input, $output, ';', true);
            self::fail('Expected fuzzy catalog initialization to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('fuzzy catalog initialization failed', $exception->getMessage());
        }

        self::assertFileDoesNotExist($output);
        self::assertSame([], glob($this->directory . '/.fuzzy-operational-error-output.csv.tmp-*'));
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, $contents);
        return $path;
    }

    private function connection(): \PDO
    {
        return (new \ReflectionProperty(SqliteAddressDirectory::class, 'pdo'))->getValue($this->pipelineDirectory());
    }

    private function pipelineDirectory(): SqliteAddressDirectory
    {
        return (new \ReflectionProperty(AddressResolutionOrchestrator::class, 'directory'))
            ->getValue((new \ReflectionProperty(AddressProcessor::class, 'resolutionOrchestrator'))
                ->getValue((new \ReflectionProperty(CsvNormalizationPipeline::class, 'processor'))->getValue($this->pipeline)));
    }
}
