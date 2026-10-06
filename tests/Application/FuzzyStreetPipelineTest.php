<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Application;

use Normalizzatore\Address\AddressInput;
use Normalizzatore\Address\AddressParser;
use Normalizzatore\Address\AddressStrategyClassifier;
use Normalizzatore\Application\AddressProcessor;
use Normalizzatore\City\CapizzatedCity;
use Normalizzatore\City\CapizzatedCityCatalog;
use Normalizzatore\Directory\FuzzyStreetCandidateSetStatus;
use Normalizzatore\Directory\SqliteAddressDirectory;
use Normalizzatore\Normalization\FieldCorrectionReason;
use Normalizzatore\Normalization\NormalizedFieldStatus;
use Normalizzatore\Resolution\AddressResolutionDiagnostic;
use Normalizzatore\Resolution\AddressResolutionOrchestrator;
use Normalizzatore\Resolution\AddressResolutionStatus;
use Normalizzatore\Resolution\CapResolver;
use Normalizzatore\Resolution\FuzzyStreetMatcher;
use Normalizzatore\Resolution\TerritorialResolver;
use Normalizzatore\Verification\SourceCapVerifier;
use Normalizzatore\Normalization\AddressFieldNormalizer;
use Normalizzatore\Tests\Support\SqliteDirectoryFixture;
use PDO;
use PHPUnit\Framework\TestCase;

final class FuzzyStreetPipelineTest extends TestCase
{
    private string $directoryPath;
    private string $databasePath;

    protected function setUp(): void
    {
        $this->directoryPath = sys_get_temp_dir() . '/normalizzatore-fuzzy-pipeline-' . bin2hex(random_bytes(6));
        mkdir($this->directoryPath);
        $this->databasePath = $this->directoryPath . '/directory.sqlite';
        SqliteDirectoryFixture::create($this->databasePath, [
            SqliteDirectoryFixture::row('VIA ENRICO FERMI', '20100', 'MILANO', 'MI', 'T', '1', '10'),
            SqliteDirectoryFixture::row('VIA EDOARDO FERMI', '20200', 'MILANO', 'MI', 'T', '1', '10'),
            SqliteDirectoryFixture::row('VIA GIUSEPPE GARIBALDI', '20300', 'MILANO', 'MI', 'T', '1', '10'),
            SqliteDirectoryFixture::row('VIA ENRICO FERMI', '20400', 'VERONA', 'VR', 'T', '1', '10'),
            SqliteDirectoryFixture::row('VIA E. FERMI', '80100', 'NAPOLI', 'NA', 'T', '1', '10'),
            SqliteDirectoryFixture::row('VIA ENRICO FERMI', '10100', 'TORINO', 'TO', 'T', '1', '10'),
            SqliteDirectoryFixture::row('VIA EDOARDO FERMI', '10200', 'TORINO', 'TO', 'T', '1', '10'),
            SqliteDirectoryFixture::row('VIA E. FERMI', '10300', 'TORINO', 'TO', 'T', '1', '10'),
            SqliteDirectoryFixture::row('VIA CAPPUCCINA', '30100', 'MILANO', 'MI', 'T', '1', '10'),
            SqliteDirectoryFixture::row('VIA ROMA', '30100', 'MILANO', 'MI', 'T', '1', '100'),
            SqliteDirectoryFixture::row('VIA GAETA', '00100', 'ROMA', 'RM', 'T', '1', '100'),
            SqliteDirectoryFixture::row('VIA ZATTA', '00200', 'ROMA', 'RM', 'T', '1', '100'),
            SqliteDirectoryFixture::row('VIA CAPPUCCINA', '40100', 'MULTI', 'AA', 'T', '1', '10'),
            SqliteDirectoryFixture::row('VIA CAPPUCCINA', '40200', 'MULTI', 'BB', 'T', '1', '10'),
            SqliteDirectoryFixture::row('VIA OLBIA', '07026', 'OLBIA', 'SS', 'T', '1', '100'),
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directoryPath . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directoryPath);
    }

    public function testUniqueAbbreviationAndTypoRequireCapResolutionBeforeStreetCorrection(): void
    {
        $processor = $this->processor();
        $abbreviation = $processor->process(new AddressInput('VIA E. FERMI 5', '20400', 'VERONA', 'VR'), true);
        self::assertSame(AddressResolutionStatus::RESOLVED, $abbreviation->resolution->status);
        self::assertSame('VIA ENRICO FERMI', $abbreviation->fieldNormalization->street->normalizedValue);
        self::assertSame(FieldCorrectionReason::FUZZY_ABBREVIATION_EXPANSION, $abbreviation->fieldNormalization->street->correction?->reason);
        self::assertContains(AddressResolutionDiagnostic::FUZZY_ABBREVIATION_MATCH, $abbreviation->resolution->diagnostics);

        $typo = $processor->process(new AddressInput('VIA CAPUCCINA 5', '30100', 'MILANO', 'MI'), true);
        self::assertSame(AddressResolutionStatus::RESOLVED, $typo->resolution->status);
        self::assertSame('VIA CAPPUCCINA', $typo->fieldNormalization->street->normalizedValue);
        self::assertSame(FieldCorrectionReason::FUZZY_TYPO_CORRECTION, $typo->fieldNormalization->street->correction?->reason);
        self::assertContains(AddressResolutionDiagnostic::FUZZY_TYPO_MATCH, $typo->resolution->diagnostics);

        $differentSourceCap = $processor->process(new AddressInput('VIA CAPUCCINA 5', '99999', 'MILANO', 'MI'), true);
        self::assertSame($typo->resolution->resolvedCap, $differentSourceCap->resolution->resolvedCap);
        self::assertSame(
            $typo->resolution->fuzzyStreetEvidence?->nominalResolution?->match?->candidate->canonicalName,
            $differentSourceCap->resolution->fuzzyStreetEvidence?->nominalResolution?->match?->candidate->canonicalName,
        );
        self::assertSame(FieldCorrectionReason::FUZZY_TYPO_CORRECTION, $differentSourceCap->fieldNormalization->street->correction?->reason);
        self::assertSame('MISMATCH', $differentSourceCap->capVerification->status->name);
    }

    public function testNominalMatchWithIncompatibleCivicDoesNotResolveOrCorrectStreet(): void
    {
        $result = $this->processor()->process(new AddressInput('VIA CAPUCCINA 9999', '30100', 'MILANO', 'MI'), true);

        self::assertSame(AddressResolutionStatus::NO_MATCH, $result->resolution->status);
        self::assertSame('MATCH', $result->resolution->fuzzyStreetEvidence?->nominalResolution?->status->name);
        self::assertNull($result->fieldNormalization->street->correction);
        self::assertNotSame(NormalizedFieldStatus::DIRECTORY_CORRECTION, $result->fieldNormalization->street->status);
        self::assertSame('', $result->normalizedCap() ?? '');
    }

    public function testTwoEditStreetTypoIsOutsideTheConservativeStepOneDistance(): void
    {
        $result = $this->processor()->process(new AddressInput('VIA CAPUCINA 5', '', 'MILANO', 'MI'), true);

        self::assertSame(AddressResolutionDiagnostic::FUZZY_NO_MATCH, $result->resolution->fuzzyStreetEvidence?->diagnostic);
        self::assertSame(AddressResolutionStatus::NO_MATCH, $result->resolution->status);
        self::assertNull($result->fieldNormalization->street->correction);
    }

    public function testAbbreviationAndTypoAmbiguityAreNotResolvedUsingCivicOrCap(): void
    {
        $processor = $this->processor();
        $abbreviation = $processor->process(new AddressInput('VIA E. FERMI 5', '20100', 'MILANO', 'MI'), true);
        self::assertSame(AddressResolutionDiagnostic::FUZZY_AMBIGUOUS, $abbreviation->resolution->fuzzyStreetEvidence?->diagnostic);
        self::assertSame(AddressResolutionStatus::NO_MATCH, $abbreviation->resolution->status);
        self::assertNull($abbreviation->fieldNormalization->street->correction);

        $typo = $processor->process(new AddressInput('VIA GATTA 5', '00100', 'ROMA', 'RM'), true);
        self::assertSame(AddressResolutionDiagnostic::FUZZY_AMBIGUOUS, $typo->resolution->fuzzyStreetEvidence?->diagnostic);
        self::assertSame(AddressResolutionStatus::NO_MATCH, $typo->resolution->status);
        self::assertNull($typo->fieldNormalization->street->correction);
    }

    public function testExactDeterministicAbbreviationSkipsLazyFuzzyCatalog(): void
    {
        $processor = $this->processor();
        $directory = $this->directory();
        $resolved = $processor->process(new AddressInput('VIA E. FERMI', '', 'TORINO', 'TO'), true);
        $civicNoMatch = $processor->process(new AddressInput('VIA E. FERMI 99', '', 'TORINO', 'TO'), true);

        self::assertSame(AddressResolutionStatus::RESOLVED, $resolved->resolution->status);
        self::assertNull($resolved->resolution->fuzzyStreetEvidence);
        self::assertSame(AddressResolutionStatus::NO_MATCH, $civicNoMatch->resolution->status);
        self::assertNull($civicNoMatch->resolution->fuzzyStreetEvidence, 'Deterministic directory rows block fuzzy even when CapResolver cannot match the civic.');
        self::assertSame(0, (int) $this->connection($directory)->query(
            "SELECT COUNT(*) FROM sqlite_temp_master WHERE name='fuzzy_street_names'",
        )->fetchColumn());
    }

    public function testParserAmbiguityDoesNotCallFuzzyProvider(): void
    {
        $processor = $this->processor();
        $directory = $this->directory();
        $parsed = (new AddressParser())->parse(new AddressInput('VIA 4 NOVEMBRE 1470', '', 'ROMA', 'RM'));
        self::assertGreaterThan(1, count($parsed->candidates));
        self::assertFalse($parsed->syntaxPreference?->hasPreferredCandidate() ?? false);

        $result = $processor->process(new AddressInput('VIA 4 NOVEMBRE 1470', '', 'ROMA', 'RM'), true);
        self::assertSame(AddressResolutionDiagnostic::FUZZY_NOT_APPLICABLE, $result->resolution->fuzzyStreetEvidence?->diagnostic);
        self::assertSame(0, (int) $this->connection($directory)->query(
            "SELECT COUNT(*) FROM sqlite_temp_master WHERE name='fuzzy_street_names'",
        )->fetchColumn());
    }

    public function testSyntaxPreferenceSelectsOnlyItsParserCandidateForFuzzy(): void
    {
        $result = $this->processor()->process(new AddressInput('VIA ROME 15', '', 'MILANO', 'MI'), true);

        self::assertSame('VIA ROME', $result->resolution->fuzzyStreetEvidence?->parserCandidate?->streetName);
        self::assertSame('VIA ROMA', $result->resolution->fuzzyStreetEvidence?->nominalResolution?->match?->candidate->canonicalName);
        self::assertSame(AddressResolutionStatus::RESOLVED, $result->resolution->status);
    }

    public function testProvinceConflictIsDiagnosticOnlyAndEmptyProvinceRequiresUniqueLocality(): void
    {
        $processor = $this->processor();
        $conflict = $processor->process(new AddressInput('VIA CAPUCCINA 5', '', 'MILANO', 'XX'), true);
        self::assertSame(AddressResolutionDiagnostic::FUZZY_PROVINCE_CONFLICT, $conflict->resolution->fuzzyStreetEvidence?->diagnostic);
        self::assertSame(FuzzyStreetCandidateSetStatus::PROVINCE_CONFLICT, $conflict->resolution->fuzzyStreetEvidence?->candidateSet?->status);
        self::assertSame([], $conflict->resolution->fuzzyStreetEvidence?->candidateSet?->candidates);
        self::assertSame(AddressResolutionStatus::NO_MATCH, $conflict->resolution->status);

        $unique = $processor->process(new AddressInput('VIA CAPUCCINA 5', '', 'MILANO', ''), true);
        self::assertSame(AddressResolutionStatus::RESOLVED, $unique->resolution->status);
        self::assertSame('MI', $unique->resolution->fuzzyStreetEvidence?->candidateSet?->provinceKey);

        $ambiguous = $processor->process(new AddressInput('VIA CAPUCCINA 5', '', 'MULTI', ''), true);
        self::assertSame(AddressResolutionDiagnostic::FUZZY_AMBIGUOUS_LOCALITY, $ambiguous->resolution->fuzzyStreetEvidence?->diagnostic);
        self::assertSame(AddressResolutionStatus::NO_MATCH, $ambiguous->resolution->status);
    }

    public function testTerritorialAndEmptyInputDoNotInitializeFuzzyCatalog(): void
    {
        $processor = $this->processor();
        $directory = $this->directory();
        $territorial = $processor->process(new AddressInput('VIA CAPUCINA 5', '', 'OLBIA', 'SS'), true);
        self::assertSame(AddressResolutionStatus::RESOLVED, $territorial->resolution->status);
        self::assertNull($territorial->resolution->fuzzyStreetEvidence);

        $empty = $processor->process(new AddressInput('', '', '', ''), true);
        self::assertSame(AddressResolutionStatus::NO_MATCH, $empty->resolution->status);
        self::assertNull($empty->resolution->fuzzyStreetEvidence);
        self::assertSame(0, (int) $this->connection($directory)->query(
            "SELECT COUNT(*) FROM sqlite_temp_master WHERE name='fuzzy_street_names'",
        )->fetchColumn());
    }

    public function testWarmFuzzyCatalogIsReusedAndFuzzyFalseRemainsDeterministic(): void
    {
        $processor = $this->processor();
        $directory = $this->directory();
        $deterministicBefore = $processor->process(new AddressInput('VIA CAPUCCINA 5', '', 'MILANO', 'MI'));
        $processor->process(new AddressInput('VIA CAPUCCINA 5', '', 'MILANO', 'MI'), true);
        $tableCountAfterFirstFuzzy = (int) $this->connection($directory)->query(
            "SELECT COUNT(*) FROM sqlite_temp_master WHERE type='table' AND name GLOB 'fuzzy_*'",
        )->fetchColumn();
        $processor->process(new AddressInput('VIA E. FERMI 5', '', 'MILANO', 'MI'), true);
        $deterministicAfter = $processor->process(new AddressInput('VIA CAPUCCINA 5', '', 'MILANO', 'MI'));

        self::assertGreaterThan(0, $tableCountAfterFirstFuzzy);
        self::assertSame($tableCountAfterFirstFuzzy, (int) $this->connection($directory)->query(
            "SELECT COUNT(*) FROM sqlite_temp_master WHERE type='table' AND name GLOB 'fuzzy_*'",
        )->fetchColumn());
        self::assertSame($deterministicBefore->resolution->status, $deterministicAfter->resolution->status);
        self::assertSame($deterministicBefore->resolution->candidateCaps, $deterministicAfter->resolution->candidateCaps);
        self::assertNull($deterministicAfter->resolution->fuzzyStreetEvidence);
    }

    private function processor(): AddressProcessor
    {
        $directory = $this->directory();
        $catalog = new CapizzatedCityCatalog([
            new CapizzatedCity('Roma', 'RM'),
            new CapizzatedCity('Milano', 'MI'),
            new CapizzatedCity('Napoli', 'NA'),
            new CapizzatedCity('Torino', 'TO'),
            new CapizzatedCity('Verona', 'VR'),
            new CapizzatedCity('Multi', 'AA'),
        ]);
        $orchestrator = new AddressResolutionOrchestrator(
            new AddressStrategyClassifier($catalog),
            new AddressParser(),
            $directory,
            new CapResolver(),
            new TerritorialResolver(),
            $directory,
            new \Normalizzatore\Resolution\FuzzyStreetMatcher(),
        );

        return new AddressProcessor($orchestrator, new SourceCapVerifier(), new AddressFieldNormalizer());
    }

    private function directory(): SqliteAddressDirectory
    {
        static $directoryByPath = [];
        if (!isset($directoryByPath[$this->databasePath])) {
            $directoryByPath[$this->databasePath] = new SqliteAddressDirectory($this->databasePath);
        }

        return $directoryByPath[$this->databasePath];
    }

    private function connection(SqliteAddressDirectory $directory): PDO
    {
        return (new \ReflectionProperty(SqliteAddressDirectory::class, 'pdo'))->getValue($directory);
    }
}
