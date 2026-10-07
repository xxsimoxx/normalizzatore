<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Resolution;

use Normalizzatore\Address\AddressInput;
use Normalizzatore\Address\AddressParser;
use Normalizzatore\Address\AddressStrategyClassifier;
use Normalizzatore\Application\AddressProcessor;
use Normalizzatore\City\CapizzatedCity;
use Normalizzatore\City\CapizzatedCityCatalog;
use Normalizzatore\City\FuzzyCityResolver;
use Normalizzatore\Directory\SqliteAddressDirectory;
use Normalizzatore\Normalization\AddressFieldNormalizer;
use Normalizzatore\Normalization\FieldCorrectionReason;
use Normalizzatore\Resolution\AddressResolutionOrchestrator;
use Normalizzatore\Resolution\AddressResolutionDiagnostic;
use Normalizzatore\Resolution\AddressResolutionStatus;
use Normalizzatore\Resolution\CapResolver;
use Normalizzatore\Resolution\FuzzyStreetMatcher;
use Normalizzatore\Resolution\TerritorialResolver;
use Normalizzatore\Tests\Support\SqliteDirectoryFixture;
use Normalizzatore\Verification\SourceCapVerifier;
use PHPUnit\Framework\TestCase;

final class TerritorialReconciliationTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            @unlink($path);
        }
    }

    public function testUniqueTerritorialEvidenceCompletesMissingProvinceOnlyAfterResolution(): void
    {
        $processor = $this->processor([
            SqliteDirectoryFixture::row('VIA ROMA', '45100', 'ROVIGO', 'RO', 'T', '1', '99'),
        ]);
        $input = new AddressInput('', '', 'Rovigo', '');
        $result = $processor->process($input, false);
        $fields = (new AddressFieldNormalizer())->normalize($input, $result->resolution);

        self::assertSame(AddressResolutionStatus::RESOLVED, $result->resolution->status);
        self::assertSame('45100', $result->resolution->resolvedCap);
        self::assertSame('RO', $fields->province->normalizedValue);
        self::assertSame(FieldCorrectionReason::PROVINCE_COMPLETION, $fields->province->correction?->reason);
    }

    public function testAmbiguousMultiProvinceCityDoesNotCompleteProvince(): void
    {
        $processor = $this->processor([
            SqliteDirectoryFixture::row('CASTRO', '24063', 'CASTRO', 'BG', '', '', ''),
            SqliteDirectoryFixture::row('CASTRO', '73030', 'CASTRO', 'LE', '', '', ''),
        ]);
        $input = new AddressInput('', '', 'Castro', '');
        $result = $processor->process($input, false);
        $fields = (new AddressFieldNormalizer())->normalize($input, $result->resolution);

        self::assertSame(AddressResolutionStatus::AMBIGUOUS, $result->resolution->status);
        self::assertNull($fields->province->normalizedValue);
        self::assertNull($fields->province->correction);
    }

    public function testFuzzyCityTieRemainsAnExplicitFieldAmbiguity(): void
    {
        $processor = $this->processor([
            SqliteDirectoryFixture::row('MONTA', '10010', 'MONTA', 'PD', '', '', ''),
            SqliteDirectoryFixture::row('SANTA', '20020', 'SANTA', 'RO', '', '', ''),
        ]);
        $input = new AddressInput('', '', 'MANTA', '');
        $result = $processor->process($input, true);

        self::assertSame(\Normalizzatore\City\FuzzyCityResolutionStatus::AMBIGUOUS, $result->resolution->fuzzyCityResolution?->status);
        self::assertSame(\Normalizzatore\Normalization\NormalizedFieldStatus::AMBIGUOUS, $result->fieldNormalization->city->status);
        self::assertNull($result->fieldNormalization->city->correction);
    }

    public function testWrongProvinceIsCorrectedFromStreetDirectoryEvidenceAndNotSourceCap(): void
    {
        $processor = $this->processor([
            SqliteDirectoryFixture::row('CORSO GIUSEPPE GARIBALDI', '35122', 'PADOVA', 'PD', 'T', '1', '20'),
        ]);
        $outputs = [];
        foreach (['35122', '99999'] as $cap) {
            $input = new AddressInput('Corso Giuseppe Garibaldi 3', $cap, 'Padova', 'VR');
            $result = $processor->process($input, false);
            $fields = (new AddressFieldNormalizer())->normalize($input, $result->resolution);
            $outputs[] = [$result->resolution->resolvedCap, $fields->province->normalizedValue];
            self::assertSame(AddressResolutionStatus::RESOLVED, $result->resolution->status);
            self::assertSame(FieldCorrectionReason::TERRITORIAL_PROVINCE_CORRECTION, $fields->province->correction?->reason);
        }

        self::assertSame([['35122', 'PD'], ['35122', 'PD']], $outputs);
    }

    public function testCityTypoIsResolvedBeforeStreetStrategyAndCanCascadeIntoStreetTypo(): void
    {
        $processor = $this->processor([
            SqliteDirectoryFixture::row('CORSO GIUSEPPE GARIBALDI', '35122', 'PADOVA', 'PD', 'T', '1', '20'),
            SqliteDirectoryFixture::row('VIA CAPPUCCINA', '35123', 'PADOVA', 'PD', 'T', '1', '20'),
        ]);
        $withoutFuzzy = new AddressInput('Via Capuccina 3', '35123', 'Padvoa', 'PD');
        $deterministic = $processor->process($withoutFuzzy, false);
        self::assertSame(AddressResolutionStatus::NO_MATCH, $deterministic->resolution->status);
        self::assertNull($deterministic->resolution->fuzzyCityResolution);

        $resolvedCaps = [];
        foreach (['35123', '99999'] as $sourceCap) {
            $input = new AddressInput('Via Capuccina 3', $sourceCap, 'Padvoa', 'PD');
            $result = $processor->process($input, true);
            $fields = (new AddressFieldNormalizer())->normalize($input, $result->resolution);

            self::assertSame(AddressResolutionStatus::RESOLVED, $result->resolution->status);
            self::assertSame('PADOVA', $fields->city->normalizedValue);
            self::assertSame('VIA CAPPUCCINA', $fields->street->normalizedValue);
            self::assertSame(FieldCorrectionReason::FUZZY_CITY_CORRECTION, $fields->city->correction?->reason);
            self::assertSame(FieldCorrectionReason::FUZZY_TYPO_CORRECTION, $fields->street->correction?->reason);
            self::assertSame('PD', $fields->province->normalizedValue);
            $resolvedCaps[] = $result->resolution->resolvedCap;
        }
        self::assertSame(['35123', '35123'], $resolvedCaps);

        $exactStreetInput = new AddressInput('Corso Giuseppe Garibaldi 3', '99999', 'Padvoa', 'PD');
        $exactStreet = $processor->process($exactStreetInput, true);
        self::assertSame(AddressResolutionStatus::RESOLVED, $exactStreet->resolution->status);
        self::assertSame(FieldCorrectionReason::FUZZY_CITY_CORRECTION, $exactStreet->fieldNormalization->city->correction?->reason);
        self::assertNull($exactStreet->resolution->fuzzyStreetEvidence);
    }

    public function testTerritorialStreetRecoveryUsesStreetCivicAndProvinceEvidenceWithoutSourceCap(): void
    {
        $processor = $this->processor([
            SqliteDirectoryFixture::row('CORSO GIUSEPPE GARIBALDI', '35122', 'PADOVA', 'PD', 'T', '1', '20'),
            SqliteDirectoryFixture::row('VIA ROMA', '45100', 'ROVIGO', 'RO', 'T', '1', '99'),
        ]);
        $deterministicInput = new AddressInput('Corso Giuseppe Garibaldi 3', '35122', 'Rovigo', 'PD');
        $deterministic = $processor->process($deterministicInput, false);
        self::assertSame(AddressResolutionStatus::INDETERMINATE, $deterministic->resolution->status);

        $outputs = [];
        foreach (['35122', '99999'] as $cap) {
            $input = new AddressInput('Corso Giuseppe Garibaldi 3', $cap, 'Rovigo', 'PD');
            $result = $processor->process($input, true);
            $fields = (new AddressFieldNormalizer())->normalize($input, $result->resolution);
            $outputs[] = [$result->resolution->status, $result->resolution->resolvedCap, $fields->city->normalizedValue];
            self::assertSame(AddressResolutionStatus::RESOLVED, $result->resolution->status);
            self::assertSame('35122', $result->resolution->resolvedCap);
            self::assertSame('PADOVA', $fields->city->normalizedValue);
            self::assertSame('CORSO GIUSEPPE GARIBALDI', $fields->street->normalizedValue);
            self::assertContains(AddressResolutionDiagnostic::TERRITORIAL_STREET_RECOVERY, $result->resolution->diagnostics);
        }

        self::assertSame([
            [AddressResolutionStatus::RESOLVED, '35122', 'PADOVA'],
            [AddressResolutionStatus::RESOLVED, '35122', 'PADOVA'],
        ], $outputs);
    }

    public function testExactCityAndItsDirectoryProvinceWinOverObsoleteSourceProvince(): void
    {
        $processor = $this->processor([
            SqliteDirectoryFixture::row("LOCALITA' S' ISCALA", '07051', 'BUDONI', 'OT', 'T', '1', '30000'),
            SqliteDirectoryFixture::row("LOCALITA' S'ISCALA", '07030', 'ERULA', 'SS', 'T', '1', '30000'),
        ]);
        $outputs = [];
        foreach (['08020', '99999'] as $sourceCap) {
            $input = new AddressInput("LOCALITA'  S'ISCALA, 1", $sourceCap, 'BUDONI', 'SS');
            $result = $processor->process($input, true);
            $fields = (new AddressFieldNormalizer())->normalize($input, $result->resolution);
            $outputs[] = [$fields->city->normalizedValue, $fields->province->normalizedValue, $result->resolution->resolvedCap];

            self::assertSame(AddressResolutionStatus::RESOLVED, $result->resolution->status);
            self::assertSame('BUDONI', $fields->city->normalizedValue);
            self::assertSame('OT', $fields->province->normalizedValue);
            self::assertSame('07051', $result->resolution->resolvedCap);
            self::assertSame(FieldCorrectionReason::TERRITORIAL_PROVINCE_RECOVERY, $fields->province->correction?->reason);
            self::assertSame("LOCALITA' S' ISCALA", $fields->street->normalizedValue);
            self::assertSame(FieldCorrectionReason::TERRITORIAL_STREET_RECOVERY, $fields->street->correction?->reason);
        }

        self::assertSame([
            ['BUDONI', 'OT', '07051'],
            ['BUDONI', 'OT', '07051'],
        ], $outputs);
    }

    public function testTerritorialRecoveryAbstainsWhenMultipleLocalitiesResolveTheStreetAndCivic(): void
    {
        $processor = $this->processor([
            SqliteDirectoryFixture::row('VIA OTHER', '01001', 'OLD TOWN', 'ZZ', '', '', ''),
            SqliteDirectoryFixture::row('VIA GARIBALDI', '02001', 'ALPHA', 'AA', 'T', '1', '10'),
            SqliteDirectoryFixture::row('VIA GARIBALDI', '03001', 'BETA', 'AA', 'T', '1', '10'),
        ]);
        $input = new AddressInput('Via Garibaldi 3', '99999', 'Old Town', 'AA');
        $result = $processor->process($input, true);

        self::assertSame(AddressResolutionStatus::INDETERMINATE, $result->resolution->status);
        self::assertNull($result->resolution->geographicEvidence);
        self::assertNotContains(AddressResolutionDiagnostic::TERRITORIAL_STREET_RECOVERY, $result->resolution->diagnostics);
        self::assertNull($result->fieldNormalization->city->correction);
        self::assertNull($result->fieldNormalization->province->correction);
    }

    public function testExactTerritorialCityIsNotReplacedBasedOnSourceCap(): void
    {
        $processor = $this->processor([
            SqliteDirectoryFixture::row('VIA ROMA', '45100', 'ROVIGO', 'RO', 'T', '1', '99'),
            SqliteDirectoryFixture::row('VIA ROMA', '35122', 'PADOVA', 'PD', 'T', '1', '99'),
        ]);
        $input = new AddressInput('', '35122', 'Rovigo', 'RO');
        $result = $processor->process($input, true);
        $fields = (new AddressFieldNormalizer())->normalize($input, $result->resolution);

        self::assertSame(AddressResolutionStatus::RESOLVED, $result->resolution->status);
        self::assertSame('45100', $result->resolution->resolvedCap);
        self::assertSame('Rovigo', $fields->city->normalizedValue);
        self::assertNull($fields->city->correction);
    }

    /** @param list<array{vianum: string, cap: string, citta: string, pr: string, pari_dispa: string, civico_da: string, civico_a: string}> $rows */
    private function processor(array $rows): AddressProcessor
    {
        $path = tempnam(sys_get_temp_dir(), 'normalizzatore-territorial-step5-');
        self::assertNotFalse($path);
        $this->temporaryPaths[] = $path;
        SqliteDirectoryFixture::create($path, $rows);
        $directory = new SqliteAddressDirectory($path);
        $orchestrator = new AddressResolutionOrchestrator(
            strategyClassifier: new AddressStrategyClassifier(new CapizzatedCityCatalog([
                new CapizzatedCity('Padova', 'PD'),
            ])),
            addressParser: new AddressParser(),
            directory: $directory,
            capResolver: new CapResolver(),
            territorialResolver: new TerritorialResolver(),
            fuzzyCandidateProvider: $directory,
            fuzzyStreetMatcher: new FuzzyStreetMatcher(),
            fuzzyCityCandidateProvider: $directory,
            fuzzyCityResolver: new FuzzyCityResolver(),
            territorialStreetRecoveryProvider: $directory,
        );

        return new AddressProcessor($orchestrator, new SourceCapVerifier(), new AddressFieldNormalizer());
    }
}
