<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Application;

use Normalizzatore\Address\AddressInput;
use Normalizzatore\Address\AddressParser;
use Normalizzatore\Address\AddressResolutionStrategy;
use Normalizzatore\Address\AddressStrategyClassifier;
use Normalizzatore\City\CapizzatedCity;
use Normalizzatore\City\CapizzatedCityCatalog;
use Normalizzatore\Directory\AddressDirectoryInterface;
use Normalizzatore\Directory\DirectoryEntry;
use Normalizzatore\Directory\SqliteAddressDirectory;
use Normalizzatore\Directory\TerritorialEntry;
use Normalizzatore\Normalization\AddressFieldNormalizer;
use Normalizzatore\Normalization\NormalizationOrigin;
use Normalizzatore\Normalization\NormalizedFieldStatus;
use Normalizzatore\Resolution\AddressResolutionOrchestrator;
use Normalizzatore\Resolution\AddressResolutionStatus;
use Normalizzatore\Resolution\CapResolver;
use Normalizzatore\Resolution\TerritorialResolver;
use Normalizzatore\Tests\Support\SqliteDirectoryFixture;
use Normalizzatore\Verification\SourceCapVerificationStatus;
use Normalizzatore\Verification\SourceCapVerifier;
use PHPUnit\Framework\TestCase;

final class AddressProcessorTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'normalizzatore-processor-');
        self::assertNotFalse($path);
        $this->databasePath = $path;
        SqliteDirectoryFixture::create($path, [
            SqliteDirectoryFixture::row('VIA ROMA', '00100', 'ROMA', 'RM', 'T', '1', '100'),
            SqliteDirectoryFixture::row('VIA GARIBALDI', '00101', 'ROMA', 'RM', 'T', '1', '100'),
            SqliteDirectoryFixture::row('VIA GARIBALDI', '00102', 'ROMA', 'RM', 'T', '1', '100'),
            SqliteDirectoryFixture::row('OLBIA', '07026', 'OLBIA', 'SS', '', '', ''),
            SqliteDirectoryFixture::row('CASTRO', '24063', 'CASTRO', 'BG', '', '', ''),
            SqliteDirectoryFixture::row('CASTRO', '73030', 'CASTRO', 'LE', '', '', ''),
            SqliteDirectoryFixture::row('PAESE', 'DISUS', 'PAESE', 'TV', '', '', ''),
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->databasePath)) {
            @unlink($this->databasePath);
        }
    }

    public function testTerritorialResolvedCapIsIntegratedAndMatchingSourceIsNotCorrected(): void
    {
        $directory = $this->directory();
        $processor = $this->processor($directory);
        $input = new AddressInput('Via Roma 50', '07026', 'Olbia', 'XX');

        $result = $processor->process($input);

        self::assertSame($input, $result->input);
        self::assertSame(AddressResolutionStrategy::TERRITORIAL, $result->resolution->strategy);
        self::assertSame(AddressResolutionStatus::RESOLVED, $result->resolution->status);
        self::assertSame('07026', $result->normalizedCap());
        self::assertSame(SourceCapVerificationStatus::MATCH, $result->capVerification->status);
        self::assertNull($result->sourceCapCorrection());
        self::assertSame($result->resolution, $result->fieldNormalization->resolutionEvidence);
        self::assertSame(NormalizedFieldStatus::SYNTAX_NORMALIZED, $result->fieldNormalization->street->status);
        self::assertSame(NormalizationOrigin::SYNTAX, $result->fieldNormalization->street->origin);
        self::assertNotNull($result->fieldNormalization->syntaxPreference?->preferredCandidate);
    }

    public function testMismatchSuggestsSourceCapCorrectionWithoutChangingNormalizedCap(): void
    {
        $result = $this->processor($this->directory())->process(
            new AddressInput('Via Roma 50', '99999', 'Olbia', 'SS'),
        );

        self::assertSame(SourceCapVerificationStatus::MISMATCH, $result->capVerification->status);
        self::assertSame('07026', $result->normalizedCap());
        self::assertSame('99999', $result->input->cap);
        self::assertSame('07026', $result->sourceCapCorrection()?->proposedCap);
        self::assertSame($result->resolution->resolvedCap, $result->normalizedCap());
    }

    public function testMissingSourceCapUsesExistingVerifierSuggestion(): void
    {
        $result = $this->processor($this->directory())->process(
            new AddressInput('Via Roma 50', '', 'Olbia', 'SS'),
        );

        self::assertSame(SourceCapVerificationStatus::SOURCE_MISSING, $result->capVerification->status);
        self::assertSame('07026', $result->normalizedCap());
        self::assertSame('07026', $result->sourceCapCorrection()?->proposedCap);
    }

    public function testAmbiguousTerritoryRemainsUnverifiableEvenWhenSourceCapIsACandidate(): void
    {
        $result = $this->processor($this->directory())->process(
            new AddressInput('Via qualunque 12', '24063', 'Castro', 'BG'),
        );

        self::assertSame(AddressResolutionStatus::AMBIGUOUS, $result->resolution->status);
        self::assertSame(['24063', '73030'], $result->resolution->candidateCaps);
        self::assertNull($result->normalizedCap());
        self::assertSame(SourceCapVerificationStatus::UNVERIFIABLE, $result->capVerification->status);
        self::assertTrue($result->capVerification->sourceCapIsCandidate);
        self::assertNull($result->sourceCapCorrection());
        self::assertSame($result->resolution->territorialResolution?->evidence, $result->fieldNormalization->resolutionEvidence->territorialResolution?->evidence);
    }

    public function testNoMatchHasNoNormalizedCapAndPreservesInput(): void
    {
        $input = new AddressInput('Via non presente 12', '00123', 'Dorgali', 'NU');
        $result = $this->processor($this->directory())->process($input);

        self::assertSame($input, $result->input);
        self::assertSame(AddressResolutionStatus::NO_MATCH, $result->resolution->status);
        self::assertNull($result->normalizedCap());
        self::assertSame(SourceCapVerificationStatus::UNVERIFIABLE, $result->capVerification->status);
        self::assertNull($result->sourceCapCorrection());
        self::assertSame([], $result->fieldCorrections());
    }

    public function testSpecialTerritorialEvidenceRemainsIndeterminate(): void
    {
        $result = $this->processor($this->directory())->process(
            new AddressInput('', '00123', 'Paese', 'TV'),
        );

        self::assertSame(AddressResolutionStatus::INDETERMINATE, $result->resolution->status);
        self::assertNull($result->normalizedCap());
        self::assertSame(SourceCapVerificationStatus::UNVERIFIABLE, $result->capVerification->status);
        self::assertSame('DISUS', $result->resolution->territorialResolution?->evidence[0]->cap);
    }

    public function testTerritorialSyntaxPreferenceSeparatesStreetAndCivicWithoutClaimingDirectorySupport(): void
    {
        $result = $this->processor($this->directory())->process(
            new AddressInput('VIA ROMA 50', '99999', 'Olbia', 'SS'),
        );

        self::assertSame('VIA ROMA', $result->fieldNormalization->street->normalizedValue);
        self::assertSame('50', $result->fieldNormalization->houseNumber->normalizedValue);
        self::assertSame(NormalizedFieldStatus::SYNTAX_NORMALIZED, $result->fieldNormalization->street->status);
        self::assertSame(NormalizationOrigin::SYNTAX, $result->fieldNormalization->street->origin);
        self::assertSame('07026', $result->normalizedCap());
        self::assertSame(SourceCapVerificationStatus::MISMATCH, $result->capVerification->status);
    }

    public function testAmbiguousSyntaxIsVisibleAlongsideTerritorialCapResolution(): void
    {
        $result = $this->processor($this->directory())->process(
            new AddressInput('VIA SARDEGNA 12/B 15', '07026', 'Olbia', 'SS'),
        );

        self::assertSame(AddressResolutionStatus::RESOLVED, $result->resolution->status);
        self::assertSame('07026', $result->normalizedCap());
        self::assertNull($result->fieldNormalization->syntaxPreference?->preferredCandidate);
        self::assertGreaterThan(1, count($result->fieldNormalization->parsedAddress?->candidates ?? []));
        self::assertContains(NormalizedFieldStatus::AMBIGUOUS, [
            $result->fieldNormalization->street->status,
            $result->fieldNormalization->houseNumber->status,
            $result->fieldNormalization->civicDetails->status,
        ]);
    }

    public function testStreetDirectoryEvidenceIsPreservedAndSourceCapDoesNotSelectCandidate(): void
    {
        $directory = $this->directory();
        $result = $this->processor($directory)->process(
            new AddressInput('VIA ROMA 50', '99999', 'Roma', 'RM'),
        );

        self::assertSame(AddressResolutionStatus::RESOLVED, $result->resolution->status);
        self::assertSame('00100', $result->normalizedCap());
        self::assertSame(SourceCapVerificationStatus::MISMATCH, $result->capVerification->status);
        self::assertSame('VIA ROMA', $result->fieldNormalization->street->normalizedValue);
        self::assertSame(NormalizationOrigin::SYNTAX, $result->fieldNormalization->street->origin);
        self::assertCount(2, $result->resolution->streetCandidateResolutions);
        self::assertSame($result->resolution, $result->fieldNormalization->resolutionEvidence);
        self::assertNotNull($result->fieldNormalization->syntaxPreference?->preferredCandidate);
        self::assertCount(2, $directory->streetCalls);
        self::assertSame([], $directory->territorialCalls);
    }

    public function testAmbiguousStreetCapResolutionDoesNotForceAmbiguousFieldStatuses(): void
    {
        $result = $this->processor($this->directory())->process(
            new AddressInput('VIA GARIBALDI', '00101', 'Roma', 'RM'),
        );

        self::assertSame(AddressResolutionStatus::AMBIGUOUS, $result->resolution->status);
        self::assertSame(['00101', '00102'], $result->resolution->candidateCaps);
        self::assertNull($result->normalizedCap());
        self::assertSame(SourceCapVerificationStatus::UNVERIFIABLE, $result->capVerification->status);
        self::assertSame(NormalizedFieldStatus::CONFIRMED, $result->fieldNormalization->street->status);
        self::assertSame(NormalizedFieldStatus::CONFIRMED, $result->fieldNormalization->city->status);
        self::assertSame(NormalizedFieldStatus::CONFIRMED, $result->fieldNormalization->province->status);
        self::assertNotSame(NormalizedFieldStatus::AMBIGUOUS, $result->fieldNormalization->street->status);
    }

    public function testEmptyInputProducesCoherentIntegratedNoMatch(): void
    {
        $directory = $this->directory();
        $result = $this->processor($directory)->process(new AddressInput('', ' ', '', ''));

        self::assertSame(AddressResolutionStatus::NO_MATCH, $result->resolution->status);
        self::assertSame(SourceCapVerificationStatus::SOURCE_MISSING, $result->capVerification->status);
        self::assertNull($result->normalizedCap());
        self::assertSame([], $result->fieldCorrections());
        self::assertNull($result->fieldNormalization->street->normalizedValue);
        self::assertSame(NormalizedFieldStatus::MISSING, $result->fieldNormalization->street->status);
        self::assertSame(NormalizedFieldStatus::MISSING, $result->fieldNormalization->houseNumber->status);
        self::assertSame(NormalizedFieldStatus::MISSING, $result->fieldNormalization->civicDetails->status);
        self::assertSame(NormalizedFieldStatus::MISSING, $result->fieldNormalization->city->status);
        self::assertSame(NormalizedFieldStatus::MISSING, $result->fieldNormalization->province->status);
        self::assertSame([], $directory->territorialCalls);
        self::assertSame([], $directory->streetCalls);
    }

    public function testSingleResolutionIsSharedWithVerifierAndNormalizerWithoutExtraLookups(): void
    {
        $directory = $this->directory();
        $result = $this->processor($directory)->process(
            new AddressInput('VIA ROMA 50', '07026', 'Olbia', 'SS'),
        );

        self::assertSame(AddressResolutionStatus::RESOLVED, $result->capVerification->resolutionStatus);
        self::assertSame($result->resolution->candidateCaps, $result->capVerification->candidateCaps);
        self::assertSame($result->resolution->resolvedCap, $result->capVerification->resolvedCap);
        self::assertSame($result->resolution, $result->fieldNormalization->resolutionEvidence);
        self::assertSame([['Olbia']], $directory->territorialCalls);
        self::assertSame([], $directory->streetCalls);
    }

    private function processor(CountingDirectory $directory): \Normalizzatore\Application\AddressProcessor
    {
        $orchestrator = new AddressResolutionOrchestrator(
            new AddressStrategyClassifier(new CapizzatedCityCatalog([
                new CapizzatedCity('Roma', 'RM'),
            ])),
            new AddressParser(),
            $directory,
            new CapResolver(),
            new TerritorialResolver(),
        );

        return new \Normalizzatore\Application\AddressProcessor(
            $orchestrator,
            new SourceCapVerifier(),
            new AddressFieldNormalizer(),
        );
    }

    private function directory(): CountingDirectory
    {
        return new CountingDirectory(new SqliteAddressDirectory($this->databasePath));
    }
}

final class CountingDirectory implements AddressDirectoryInterface
{
    /** @var list<array{0: string, 1: string, 2: string}> */
    public array $streetCalls = [];

    /** @var list<array{0: string}> */
    public array $territorialCalls = [];

    public function __construct(private readonly SqliteAddressDirectory $inner)
    {
    }

    public function findByStreetCityProvince(string $street, string $city, string $province): array
    {
        $this->streetCalls[] = [$street, $city, $province];

        return $this->inner->findByStreetCityProvince($street, $city, $province);
    }

    public function findTerritorialEntries(string $city): array
    {
        $this->territorialCalls[] = [$city];

        return $this->inner->findTerritorialEntries($city);
    }
}
