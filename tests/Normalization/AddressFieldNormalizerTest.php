<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Normalization;

use Normalizzatore\Address\AddressCandidate;
use Normalizzatore\Address\AddressInput;
use Normalizzatore\Address\AddressParser;
use Normalizzatore\Address\AddressResolutionStrategy;
use Normalizzatore\Address\AddressStrategyClassifier;
use Normalizzatore\Address\HouseNumber;
use Normalizzatore\City\CapizzatedCity;
use Normalizzatore\City\CapizzatedCityCatalog;
use Normalizzatore\Directory\DirectoryEntry;
use Normalizzatore\Directory\SqliteAddressDirectory;
use Normalizzatore\Directory\TerritorialEntry;
use Normalizzatore\Normalization\AddressFieldDiagnostic;
use Normalizzatore\Normalization\AddressFieldNormalizer;
use Normalizzatore\Normalization\FieldCorrectionReason;
use Normalizzatore\Normalization\NormalizedFieldStatus;
use Normalizzatore\Normalization\NormalizationOrigin;
use Normalizzatore\Resolution\AddressResolution;
use Normalizzatore\Resolution\AddressResolutionOrchestrator;
use Normalizzatore\Resolution\AddressResolutionStatus;
use Normalizzatore\Resolution\CapResolution;
use Normalizzatore\Resolution\CapResolutionBasis;
use Normalizzatore\Resolution\CapResolutionDiagnostic;
use Normalizzatore\Resolution\CapResolutionStatus;
use Normalizzatore\Resolution\CapResolver;
use Normalizzatore\Resolution\TerritorialResolution;
use Normalizzatore\Resolution\TerritorialResolutionStatus;
use Normalizzatore\Resolution\TerritorialResolver;
use Normalizzatore\Resolution\StreetCandidateResolution;
use Normalizzatore\Tests\Support\SqliteDirectoryFixture;
use PHPUnit\Framework\TestCase;

final class AddressFieldNormalizerTest extends TestCase
{
    public function testParsesStreetCivicNumberAndOpaqueLetterWithoutChangingTheSource(): void
    {
        $input = new AddressInput('Via Roma 15 A', '00100', 'Roma', 'RM');
        $parser = new AddressParser();
        $candidates = $parser->parse($input)->candidates;
        $target = $this->candidate($candidates, 'Via Roma', '15', 'A');
        $entry = $this->entry(1, 'VIA ROMA', '00100', 'ROMA', 'RM');
        $result = (new AddressFieldNormalizer())->normalize($input, $this->streetResolution($target, [$entry]));

        self::assertSame('VIA ROMA', $result->street->normalizedValue);
        self::assertSame('15', $result->houseNumber->normalizedValue);
        self::assertSame('A', $result->civicDetails->normalizedValue);
        self::assertSame(NormalizedFieldStatus::CONFIRMED, $result->houseNumber->status);
        self::assertSame(NormalizationOrigin::SYNTAX, $result->houseNumber->origin);
        self::assertSame(NormalizationOrigin::SYNTAX, $result->civicDetails->origin);
        self::assertSame('Via Roma 15 A', $result->sourceVianum);
        self::assertSame('Via Roma 15 A', $input->vianum);
    }

    public function testKeepsNumbersInStreetNameAndSeparatesCivicNumber(): void
    {
        $input = new AddressInput('Via 8 Luglio 15 A', null, 'Roma', 'RM');
        $target = $this->candidate((new AddressParser())->parse($input)->candidates, 'Via 8 Luglio', '15', 'A');
        $entry = $this->entry(1, 'VIA 8 LUGLIO', '00100', 'ROMA', 'RM');
        $result = (new AddressFieldNormalizer())->normalize($input, $this->streetResolution($target, [$entry]));

        self::assertSame('VIA 8 LUGLIO', $result->street->normalizedValue);
        self::assertSame('15', $result->houseNumber->normalizedValue);
        self::assertSame('A', $result->civicDetails->normalizedValue);
    }

    public function testPreservesInternalAndScaleDetailsAsOpaqueStrings(): void
    {
        foreach (['Via Roma 15 interno 3' => 'interno 3', 'Via Roma 15 scala B' => 'scala B'] as $source => $expected) {
            $input = new AddressInput($source, null, 'Roma', 'RM');
            $target = $this->candidate((new AddressParser())->parse($input)->candidates, 'Via Roma', '15', $expected);
            $result = (new AddressFieldNormalizer())->normalize(
                $input,
                $this->streetResolution($target, [$this->entry(1, 'VIA ROMA', '00100', 'ROMA', 'RM')]),
            );
            self::assertSame('15', $result->houseNumber->normalizedValue);
            self::assertSame($expected, $result->civicDetails->normalizedValue);
        }
    }

    public function testMissingCivicNumberViaCityAndProvinceAreRepresentedIndependently(): void
    {
        $input = new AddressInput('Via Roma SNC', null, null, '');
        $candidate = (new AddressParser())->parse($input)->candidates[0];
        $result = (new AddressFieldNormalizer())->normalize($input, $this->streetResolution(
            $candidate,
            [$this->entry(1, 'VIA ROMA', '00100', 'ROMA', 'RM')],
        ));

        self::assertSame(NormalizedFieldStatus::MISSING, $result->houseNumber->status);
        self::assertSame(NormalizedFieldStatus::MISSING, $result->civicDetails->status);
        self::assertSame(NormalizedFieldStatus::MISSING, $result->city->status);
        self::assertSame(NormalizedFieldStatus::MISSING, $result->province->status);
    }

    public function testCityAndProvinceWhitespaceCanBeNormalizedWithoutLexicalCorrections(): void
    {
        $input = new AddressInput('Via Roma', null, '  Roma   Centro ', ' rm ');
        $resolution = $this->territorialResolution([
            new TerritorialEntry('Roma Centro', 'RM', '00100', 3),
        ]);
        $result = (new AddressFieldNormalizer())->normalize($input, $resolution);

        self::assertSame('Roma Centro', $result->city->normalizedValue);
        self::assertSame(NormalizedFieldStatus::SYNTAX_NORMALIZED, $result->city->status);
        self::assertSame('rm', $result->province->normalizedValue);
        self::assertSame(NormalizedFieldStatus::SYNTAX_NORMALIZED, $result->province->status);
        self::assertSame('Via Roma', $result->street->normalizedValue);
        self::assertSame(NormalizedFieldStatus::UNVERIFIABLE, $result->street->status);
    }

    public function testSyntaxCorrectionCanRemainUnverifiableWithoutDirectoryEvidence(): void
    {
        $input = new AddressInput('Via  Roma SNC', null, 'Olbia', 'SS');
        $resolution = $this->territorialResolution([]);
        $result = (new AddressFieldNormalizer())->normalize($input, $resolution);

        self::assertSame('Via  Roma', $result->street->originalValue);
        self::assertSame('Via Roma', $result->street->normalizedValue);
        self::assertSame(NormalizedFieldStatus::UNVERIFIABLE, $result->street->status);
        self::assertSame(NormalizationOrigin::SYNTAX, $result->street->origin);
        self::assertSame(FieldCorrectionReason::WHITESPACE_NORMALIZATION, $result->street->correction?->reason);
        self::assertSame('Via  Roma SNC', $input->vianum);
        self::assertSame($resolution, $result->resolutionEvidence);
    }

    public function testDirectoryCanCanonicalizeStreetOnlyWhenItsSpellingIsUnique(): void
    {
        $input = new AddressInput('via roma', null, 'Roma', 'RM');
        $candidate = (new AddressParser())->parse($input)->candidates[0];
        $resolution = $this->streetResolution($candidate, [
            $this->entry(1, 'VIA ROMA', '00100', 'ROMA', 'RM'),
            $this->entry(2, 'VIA ROMA', '00101', 'ROMA', 'RM'),
        ]);
        $result = (new AddressFieldNormalizer())->normalize($input, $resolution);

        self::assertSame('VIA ROMA', $result->street->normalizedValue);
        self::assertSame(NormalizedFieldStatus::DIRECTORY_CORRECTION, $result->street->status);
        self::assertSame(NormalizationOrigin::DIRECTORY, $result->street->origin);
        self::assertCount(1, $result->suggestedCorrections());
    }

    public function testDirectoryCaseVariantsDoNotCreateFalseStreetAmbiguity(): void
    {
        $input = new AddressInput('via roma 15', null, 'Roma', 'RM');
        $candidate = $this->candidate((new AddressParser())->parse($input)->candidates, 'via roma', '15', '');
        $resolution = $this->streetResolution($candidate, [
            $this->entry(1, 'VIA ROMA', '00100', 'ROMA', 'RM'),
            $this->entry(2, 'via roma', '00101', 'ROMA', 'RM'),
        ]);
        $result = (new AddressFieldNormalizer())->normalize($input, $resolution);

        self::assertSame('via roma', $result->street->normalizedValue);
        self::assertSame(NormalizedFieldStatus::CONFIRMED, $result->street->status);
        self::assertNull($result->street->correction);
        self::assertNotContains(AddressFieldDiagnostic::MULTIPLE_DIRECTORY_SPELLINGS, $result->street->diagnostics);
    }

    public function testDifferentMatchedStreetsRemainAmbiguousEvenWhenTheirCapsAgree(): void
    {
        $first = new AddressCandidate('Via Roma 15', null, '');
        $second = new AddressCandidate('Via Roma', new HouseNumber('15'), '');
        $firstEntry = $this->entry(1, 'VIA ROMA 15', '00100', 'ROMA', 'RM');
        $secondEntry = $this->entry(2, 'VIA ROMA', '00100', 'ROMA', 'RM');
        $resolution = new AddressResolution(
            AddressResolutionStrategy::STREET_BASED,
            AddressResolutionStatus::RESOLVED,
            ['00100'],
            '00100',
            null,
            [
                $this->candidateResolution($first, [$firstEntry], ['00100']),
                $this->candidateResolution($second, [$secondEntry], ['00100']),
            ],
            [],
        );
        $result = (new AddressFieldNormalizer())->normalize(new AddressInput('Via Roma 15', null, 'Roma', 'RM'), $resolution);

        self::assertSame(NormalizedFieldStatus::AMBIGUOUS, $result->street->status);
        self::assertSame(NormalizedFieldStatus::AMBIGUOUS, $result->houseNumber->status);
        self::assertContains(AddressFieldDiagnostic::MULTIPLE_PARSER_INTERPRETATIONS, $result->street->diagnostics);
        self::assertSame($resolution, $result->resolutionEvidence);
    }

    public function testFieldsAreResolvedIndependentlyWhenStreetSyntaxIsEquivalent(): void
    {
        $first = new AddressCandidate('Via Roma ', new HouseNumber('15'), '');
        $second = new AddressCandidate('Via   Roma', new HouseNumber('16'), '');
        $entry = $this->entry(1, 'VIA ROMA', '00100', 'ROMA', 'RM');
        $resolution = new AddressResolution(
            AddressResolutionStrategy::STREET_BASED,
            AddressResolutionStatus::AMBIGUOUS,
            ['00100', '00101'],
            null,
            null,
            [
                $this->candidateResolution($first, [$entry], ['00100']),
                $this->candidateResolution($second, [$entry], ['00100']),
            ],
            [],
        );
        $result = (new AddressFieldNormalizer())->normalize(new AddressInput('Via Roma 15', null, 'Roma', 'RM'), $resolution);

        self::assertSame('VIA ROMA', $result->street->normalizedValue);
        self::assertSame(NormalizedFieldStatus::DIRECTORY_CORRECTION, $result->street->status);
        self::assertSame(NormalizedFieldStatus::AMBIGUOUS, $result->houseNumber->status);
        self::assertSame($resolution, $result->resolutionEvidence);
    }

    public function testTerritorialEvidenceDoesNotUseCapToChangeStreetOrProvince(): void
    {
        $input = new AddressInput('  Via   Roma 15  ', null, ' roma ', 'xx');
        $result = (new AddressFieldNormalizer())->normalize($input, $this->territorialResolution([
            new TerritorialEntry('ROMA', 'RM', '00100', 2),
        ]));

        self::assertSame('roma', $result->city->normalizedValue);
        self::assertSame(NormalizedFieldStatus::SYNTAX_NORMALIZED, $result->city->status);
        self::assertSame('xx', $result->province->normalizedValue);
        self::assertSame(NormalizedFieldStatus::UNVERIFIABLE, $result->province->status);
        self::assertContains(AddressFieldDiagnostic::PROVINCE_SIGLA_DIFFERS_FROM_DIRECTORY, $result->province->diagnostics);
        self::assertSame(NormalizedFieldStatus::AMBIGUOUS, $result->street->status);
        self::assertSame(NormalizedFieldStatus::AMBIGUOUS, $result->houseNumber->status);
    }

    public function testTerritorialMultipleProvincesDoNotProduceAProvinceCorrection(): void
    {
        $input = new AddressInput('', null, 'Tinnura', 'SS');
        $result = (new AddressFieldNormalizer())->normalize($input, $this->territorialResolution([
            new TerritorialEntry('TINNURA', 'NU', '08010', 2),
            new TerritorialEntry('TINNURA', 'OR', '08010', 1),
        ]));

        self::assertSame(NormalizedFieldStatus::UNVERIFIABLE, $result->province->status);
        self::assertNull($result->province->correction);
        self::assertContains(AddressFieldDiagnostic::MULTIPLE_DIRECTORY_PROVINCES, $result->province->diagnostics);
    }

    public function testCityCaseDifferenceKeepsSourceSpellingInsteadOfDirectoryCorrection(): void
    {
        $input = new AddressInput('', null, 'BERGAMO', null);
        $result = (new AddressFieldNormalizer())->normalize($input, $this->territorialResolution([
            new TerritorialEntry('Bergamo', 'BG', '24100', 1),
        ]));

        self::assertSame('BERGAMO', $result->city->normalizedValue);
        self::assertSame(NormalizedFieldStatus::CONFIRMED, $result->city->status);
        self::assertNull($result->city->correction);
    }

    public function testCityWhitespaceDifferenceIsSyntaxOnly(): void
    {
        $input = new AddressInput('', null, ' Bergamo   Centro ', null);
        $result = (new AddressFieldNormalizer())->normalize($input, $this->territorialResolution([
            new TerritorialEntry('BERGAMO CENTRO', 'BG', '24100', 1),
        ]));

        self::assertSame('Bergamo Centro', $result->city->normalizedValue);
        self::assertSame(NormalizedFieldStatus::SYNTAX_NORMALIZED, $result->city->status);
        self::assertSame(NormalizationOrigin::SYNTAX, $result->city->correction?->origin);
    }

    public function testLexicallyDifferentCityIsReportedWithoutDirectoryCorrection(): void
    {
        $input = new AddressInput('', null, 'San Teodoro', 'OT');
        $result = (new AddressFieldNormalizer())->normalize($input, $this->territorialResolution([
            new TerritorialEntry('Olbia', 'OT', '07026', 1),
        ]));

        self::assertSame('San Teodoro', $result->city->normalizedValue);
        self::assertSame(NormalizedFieldStatus::UNVERIFIABLE, $result->city->status);
        self::assertNull($result->city->correction);
        self::assertContains(AddressFieldDiagnostic::CITY_NAME_DIFFERS_FROM_DIRECTORY, $result->city->diagnostics);
    }

    public function testProvinceCaseAndWhitespaceDifferenceIsSyntaxOnly(): void
    {
        $input = new AddressInput('', null, 'Roma', ' rm ');
        $result = (new AddressFieldNormalizer())->normalize($input, $this->territorialResolution([
            new TerritorialEntry('ROMA', 'RM', '00100', 1),
        ]));

        self::assertSame('rm', $result->province->normalizedValue);
        self::assertSame(NormalizedFieldStatus::SYNTAX_NORMALIZED, $result->province->status);
        self::assertSame(NormalizationOrigin::SYNTAX, $result->province->correction?->origin);
    }

    public function testDifferentValidProvinceSiglaIsDiagnosticAndNotCorrection(): void
    {
        $input = new AddressInput('', null, 'Olbia', 'SS');
        $result = (new AddressFieldNormalizer())->normalize($input, $this->territorialResolution([
            new TerritorialEntry('OLBIA', 'OT', '07026', 1),
        ]));

        self::assertSame('SS', $result->province->normalizedValue);
        self::assertSame(NormalizedFieldStatus::UNVERIFIABLE, $result->province->status);
        self::assertNull($result->province->correction);
        self::assertContains(AddressFieldDiagnostic::PROVINCE_SIGLA_DIFFERS_FROM_DIRECTORY, $result->province->diagnostics);
    }

    public function testMissingAndInvalidSourceProvinceAreNotReplacedFromDirectory(): void
    {
        $evidence = [new TerritorialEntry('ROMA', 'RM', '00100', 1)];
        $normalizer = new AddressFieldNormalizer();

        $missing = $normalizer->normalize(new AddressInput('', null, 'Roma', null), $this->territorialResolution($evidence));
        self::assertSame(NormalizedFieldStatus::MISSING, $missing->province->status);
        self::assertNull($missing->province->correction);
        self::assertContains(AddressFieldDiagnostic::PROVINCE_SOURCE_MISSING, $missing->province->diagnostics);

        $invalid = $normalizer->normalize(new AddressInput('', null, 'Roma', 'R1'), $this->territorialResolution($evidence));
        self::assertSame('R1', $invalid->province->normalizedValue);
        self::assertSame(NormalizedFieldStatus::UNVERIFIABLE, $invalid->province->status);
        self::assertNull($invalid->province->correction);
        self::assertContains(AddressFieldDiagnostic::PROVINCE_SOURCE_INVALID, $invalid->province->diagnostics);
    }

    public function testInvalidDirectoryProvinceIsNotSuggested(): void
    {
        $input = new AddressInput('', null, 'Paese', 'TV');
        $result = (new AddressFieldNormalizer())->normalize($input, $this->territorialResolution([
            new TerritorialEntry('PAESE', 'X1', '31038', 1),
        ]));

        self::assertSame(NormalizedFieldStatus::UNVERIFIABLE, $result->province->status);
        self::assertNull($result->province->correction);
        self::assertContains(AddressFieldDiagnostic::INVALID_DIRECTORY_PROVINCE, $result->province->diagnostics);
    }

    public function testCompletelyEmptyInputProducesMissingFields(): void
    {
        $input = new AddressInput('', null, null, null);
        $resolution = new AddressResolution(
            AddressResolutionStrategy::TERRITORIAL,
            AddressResolutionStatus::NO_MATCH,
            [],
            null,
            new TerritorialResolution(TerritorialResolutionStatus::NO_MATCH, [], null, [], []),
            [],
            [],
        );
        $result = (new AddressFieldNormalizer())->normalize($input, $resolution);

        foreach ([$result->street, $result->houseNumber, $result->civicDetails, $result->city, $result->province] as $field) {
            self::assertSame(NormalizedFieldStatus::MISSING, $field->status);
            self::assertNull($field->normalizedValue);
        }
    }

    public function testUnresolvedTerritorialStreetRetainsSourceAndDoesNotInventFields(): void
    {
        $input = new AddressInput('Via Roma 15', null, 'Olbia', null);
        $resolution = new AddressResolution(
            AddressResolutionStrategy::TERRITORIAL,
            AddressResolutionStatus::NO_MATCH,
            [],
            null,
            new TerritorialResolution(TerritorialResolutionStatus::NO_MATCH, [], null, [], []),
            [],
            [],
        );
        $result = (new AddressFieldNormalizer())->normalize($input, $resolution);

        self::assertSame('Via Roma 15', $result->sourceVianum);
        self::assertSame(NormalizedFieldStatus::AMBIGUOUS, $result->street->status);
        self::assertSame(NormalizedFieldStatus::UNVERIFIABLE, $result->city->status);
        self::assertSame([], $result->suggestedCorrections());
    }

    public function testSqliteOrchestratorEvidenceIsReusedWithoutAdditionalDirectoryLookups(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'address-fields-');
        self::assertNotFalse($path);
        try {
            SqliteDirectoryFixture::create($path, [
                SqliteDirectoryFixture::row('VIA ROMA', '00100', 'ROMA', 'RM', 'T', '1', '30000'),
                SqliteDirectoryFixture::row('VIA 8 LUGLIO', '00101', 'ROMA', 'RM', 'T', '1', '30000'),
            ]);
            $directory = new SqliteAddressDirectory($path);
            $orchestrator = new AddressResolutionOrchestrator(
                new AddressStrategyClassifier(new CapizzatedCityCatalog([new CapizzatedCity('ROMA', 'RM')])),
                new AddressParser(),
                $directory,
                new CapResolver(),
                new TerritorialResolver(),
            );
            $normalizer = new AddressFieldNormalizer();
            $input = new AddressInput('Via Roma 15', '99999', 'Roma', 'RM');
            $resolution = $orchestrator->resolve($input);
            $before = $directory->findByStreetCityProvince('Via Roma', 'Roma', 'RM');
            $normalized = $normalizer->normalize($input, $resolution);
            $after = $directory->findByStreetCityProvince('Via Roma', 'Roma', 'RM');

            self::assertSame('00100', $resolution->resolvedCap);
            self::assertSame(
                array_map(static fn (DirectoryEntry $entry): array => [$entry->id, $entry->vianum, $entry->cap], $before),
                array_map(static fn (DirectoryEntry $entry): array => [$entry->id, $entry->vianum, $entry->cap], $after),
            );
            self::assertSame('15', $normalized->houseNumber->normalizedValue);
            self::assertSame('VIA ROMA', $normalized->street->normalizedValue);

            $territorialInput = new AddressInput('Via qualunque', '99999', 'Olbia', 'SS');
            $territorialOrchestrator = new AddressResolutionOrchestrator(
                new AddressStrategyClassifier(new CapizzatedCityCatalog([])),
                new AddressParser(),
                $directory,
                new CapResolver(),
                new TerritorialResolver(),
            );
            $territorialResolution = $territorialOrchestrator->resolve($territorialInput);
            self::assertSame([], $territorialResolution->territorialResolution?->evidence);
            $territorialFields = $normalizer->normalize($territorialInput, $territorialResolution);
            self::assertSame(NormalizedFieldStatus::UNVERIFIABLE, $territorialFields->street->status);
            self::assertSame([], $territorialFields->resolutionEvidence->streetCandidateResolutions);
        } finally {
            @unlink($path);
        }
    }

    public function testSqliteIntegrationPreservesMultipleStreetAndTerritorialInterpretations(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'address-fields-');
        self::assertNotFalse($path);
        try {
            SqliteDirectoryFixture::create($path, [
                SqliteDirectoryFixture::row('VIA ROMA 15', '00100', 'ROMA', 'RM', 'T', '1', '30000'),
                SqliteDirectoryFixture::row('VIA ROMA', '00100', 'ROMA', 'RM', 'T', '1', '30000'),
                SqliteDirectoryFixture::row('VIA TINNURA', '08010', 'TINNURA', 'NU', 'T', '1', '30000'),
                SqliteDirectoryFixture::row('VIA TINNURA', '08010', 'TINNURA', 'OR', 'T', '1', '30000'),
            ]);
            $directory = new SqliteAddressDirectory($path);
            $normalizer = new AddressFieldNormalizer();
            $streetInput = new AddressInput('Via Roma 15', null, 'Roma', 'RM');
            $streetOrchestrator = new AddressResolutionOrchestrator(
                new AddressStrategyClassifier(new CapizzatedCityCatalog([new CapizzatedCity('ROMA', 'RM')])),
                new AddressParser(),
                $directory,
                new CapResolver(),
                new TerritorialResolver(),
            );
            $streetResolution = $streetOrchestrator->resolve($streetInput);
            $streetFields = $normalizer->normalize($streetInput, $streetResolution);
            self::assertSame(AddressResolutionStatus::RESOLVED, $streetResolution->status);
            self::assertSame(NormalizedFieldStatus::AMBIGUOUS, $streetFields->street->status);
            self::assertCount(2, $streetResolution->streetCandidateResolutions);

            $territorialInput = new AddressInput('', null, 'Tinnura', 'XX');
            $territorialOrchestrator = new AddressResolutionOrchestrator(
                new AddressStrategyClassifier(new CapizzatedCityCatalog([])),
                new AddressParser(),
                $directory,
                new CapResolver(),
                new TerritorialResolver(),
            );
            $territorialResolution = $territorialOrchestrator->resolve($territorialInput);
            $territorialFields = $normalizer->normalize($territorialInput, $territorialResolution);
            self::assertSame(AddressResolutionStatus::RESOLVED, $territorialResolution->status);
            self::assertSame(NormalizedFieldStatus::UNVERIFIABLE, $territorialFields->province->status);
            self::assertSame(['NU', 'OR'], array_values(array_unique(array_map(
                static fn (TerritorialEntry $entry): string => $entry->province,
                $territorialResolution->territorialResolution?->evidence ?? [],
            ))));
        } finally {
            @unlink($path);
        }
    }

    /** @param list<AddressCandidate> $candidates */
    private function candidate(array $candidates, string $street, ?string $number, string $details): AddressCandidate
    {
        foreach ($candidates as $candidate) {
            if ($candidate->streetName === $street
                && $candidate->houseNumber?->number === $number
                && $candidate->trailingInformation === $details) {
                return $candidate;
            }
        }

        self::fail('Expected parser candidate was not produced.');
    }

    private function streetResolution(AddressCandidate $candidate, array $entries): AddressResolution
    {
        return new AddressResolution(
            AddressResolutionStrategy::STREET_BASED,
            AddressResolutionStatus::RESOLVED,
            ['00100'],
            '00100',
            null,
            [$this->candidateResolution($candidate, $entries, ['00100'])],
            [],
        );
    }

    /** @param list<DirectoryEntry> $entries @param list<string> $caps */
    private function candidateResolution(AddressCandidate $candidate, array $entries, array $caps): StreetCandidateResolution
    {
        return new StreetCandidateResolution($candidate, $entries, new CapResolution(
            CapResolutionStatus::RESOLVED,
            CapResolutionBasis::CIVIC_RANGE,
            $caps,
            $entries,
            [],
            [],
        ));
    }

    /** @param list<TerritorialEntry> $entries */
    private function territorialResolution(array $entries): AddressResolution
    {
        $caps = array_values(array_unique(array_filter(array_map(
            static fn (TerritorialEntry $entry): string => $entry->cap,
            $entries,
        ), static fn (string $cap): bool => preg_match('/\A[0-9]{5}\z/', $cap) === 1)));
        sort($caps, SORT_STRING);
        $status = match (count($caps)) {
            0 => $entries === [] ? TerritorialResolutionStatus::NO_MATCH : TerritorialResolutionStatus::INDETERMINATE,
            1 => TerritorialResolutionStatus::RESOLVED,
            default => TerritorialResolutionStatus::AMBIGUOUS,
        };
        $territorial = new TerritorialResolution($status, $caps, $status === TerritorialResolutionStatus::RESOLVED ? $caps[0] : null, $entries, []);

        return new AddressResolution(
            AddressResolutionStrategy::TERRITORIAL,
            match ($status) {
                TerritorialResolutionStatus::RESOLVED => AddressResolutionStatus::RESOLVED,
                TerritorialResolutionStatus::NO_MATCH => AddressResolutionStatus::NO_MATCH,
                TerritorialResolutionStatus::AMBIGUOUS => AddressResolutionStatus::AMBIGUOUS,
                TerritorialResolutionStatus::INDETERMINATE => AddressResolutionStatus::INDETERMINATE,
            },
            $caps,
            $territorial->resolvedCap,
            $territorial,
            [],
            [],
        );
    }

    private function entry(int $id, string $street, string $cap, string $city, string $province): DirectoryEntry
    {
        return new DirectoryEntry($id, $street, $cap, $city, $province, 'T', '1', '30000');
    }
}
