<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Application;

use Normalizzatore\Address\AddressInput;
use Normalizzatore\Address\AddressParser;
use Normalizzatore\Address\AddressStrategyClassifier;
use Normalizzatore\Application\AddressProcessor;
use Normalizzatore\City\CapizzatedCity;
use Normalizzatore\City\CapizzatedCityCatalog;
use Normalizzatore\City\CityCandidate;
use Normalizzatore\Directory\SqliteAddressDirectory;
use Normalizzatore\Frazione\FrazioneCatalog;
use Normalizzatore\Frazione\FrazioneResolutionStatus;
use Normalizzatore\Frazione\FrazioneStreetEvidenceStatus;
use Normalizzatore\Frazione\FuzzyFrazioneResolutionStatus;
use Normalizzatore\Normalization\AddressFieldNormalizer;
use Normalizzatore\Normalization\FieldCorrectionReason;
use Normalizzatore\Resolution\AddressResolutionOrchestrator;
use Normalizzatore\Resolution\CapResolver;
use Normalizzatore\Resolution\TerritorialResolver;
use Normalizzatore\Verification\SourceCapVerifier;
use Normalizzatore\Tests\Support\SqliteDirectoryFixture;
use PHPUnit\Framework\TestCase;

final class FrazionePipelineTest extends TestCase
{
    private string $directory;
    private ?FrazioneCatalog $catalog = null;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/normalizzatore-frazione-' . bin2hex(random_bytes(5));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testFienilDelTurcoUsesVerifiedRovigoStreetResolutionOnlyWhenEnabled(): void
    {
        $processor = $this->processor("CAP\tCOMUNE\tFRAZIONE\tPROVINCIA\tTIPO\n45100\tRovigo\tFienil del Turco\tRO\tCentro abitato\n");
        $input = new AddressInput('VIA ROMA 1', '45100', 'Fienil del Turco', 'RO');

        $disabled = $processor->process($input);
        $enabled = $processor->process($input, false, true);
        $enabledWithFuzzy = $processor->process($input, true, true);

        self::assertNull($disabled->resolution->frazioneResolution);
        self::assertSame(FrazioneResolutionStatus::MATCH, $enabled->resolution->frazioneResolution?->status);
        self::assertSame('RESOLVED', $enabled->resolution->status->name);
        self::assertSame('Rovigo', $enabled->fieldNormalization->city->normalizedValue);
        self::assertSame(FieldCorrectionReason::FRAZIONE_TO_COMUNE, $enabled->fieldNormalization->city->correction?->reason);
        self::assertStringContainsString('FRAZIONE:MATCH', $this->diagnosticText($enabled));
        self::assertStringContainsString('Centro abitato', $this->diagnosticText($enabled));
        self::assertSame('RESOLVED', $enabledWithFuzzy->resolution->status->name);
        self::assertSame('Rovigo', $enabledWithFuzzy->fieldNormalization->city->normalizedValue);
    }

    public function testSourceCapDoesNotInfluenceFuzzyFrazioneMatchOrResolution(): void
    {
        $processor = $this->processor("CAP\tCOMUNE\tFRAZIONE\tPROVINCIA\tTIPO\n45100\tRovigo\tFienil del Turco\tRO\tCentro abitato\n");
        $input = static fn (string $cap): AddressInput => new AddressInput('VIA ROMA 1', $cap, 'FIENILE DEL TURCO', 'RO');

        $validCap = $processor->process($input('45100'), true, true);
        $wrongCap = $processor->process($input('99999'), true, true);

        self::assertSame('FIENIL DEL TURCO', $validCap->resolution->fuzzyFrazioneResolution?->selectedCandidate?->canonicalName);
        self::assertSame($validCap->resolution->fuzzyFrazioneResolution?->selectedCandidate?->canonicalName, $wrongCap->resolution->fuzzyFrazioneResolution?->selectedCandidate?->canonicalName);
        self::assertSame($validCap->resolution->status, $wrongCap->resolution->status);
        self::assertSame('45100', $wrongCap->resolution->resolvedCap);
        self::assertSame('MISMATCH', $wrongCap->capVerification->status->name);
        self::assertSame('Rovigo', $wrongCap->fieldNormalization->city->normalizedValue);
    }

    public function testGrignanoPolesineStillResolvesToRovigo(): void
    {
        $processor = $this->processor("45100\tRovigo\tGrignano Polesine\tRO\tCentro abitato\n");
        $result = $processor->process(new AddressInput('VIA ROMA 8', '45100', 'Grignano Polesine', 'RO'), false, true);

        self::assertSame(FrazioneResolutionStatus::MATCH, $result->resolution->frazioneResolution?->status);
        self::assertSame('Rovigo', $result->fieldNormalization->city->normalizedValue);
    }

    public function testExactMunicipalityTakesPrecedenceOverCatalogFractionWithSameName(): void
    {
        $processor = $this->processor("CAP\tCOMUNE\tFRAZIONE\tPROVINCIA\tTIPO\n00100\tElsewhere\tRovigo\tXX\tNucleo abitato\n");
        self::assertFalse($this->catalog?->isLoaded());
        $result = $processor->process(new AddressInput('VIA ROMA 1', '45100', 'Rovigo', 'RO'), false, true);

        self::assertFalse($this->catalog?->isLoaded());
        self::assertNull($result->resolution->frazioneResolution);
        self::assertSame('Rovigo', $result->fieldNormalization->city->normalizedValue);
        self::assertNull($result->fieldNormalization->city->correction);
    }

    public function testCatalogRemainsLazyWhenDisabledAndIsReusedForSubsequentCalls(): void
    {
        $processor = $this->processor("CAP\tCOMUNE\tFRAZIONE\tPROVINCIA\tTIPO\n45100\tRovigo\tFienil del Turco\tRO\tCentro abitato\n");
        self::assertFalse($this->catalog?->isLoaded());
        $input = new AddressInput('VIA ROMA 1', '45100', 'Fienil del Turco', 'RO');
        $processor->process($input);
        self::assertFalse($this->catalog?->isLoaded());
        $processor->process($input, false, true);
        self::assertTrue($this->catalog?->isLoaded());
        self::assertSame(1, $this->catalog?->rowCount());
        $processor->process($input, false, true);
        self::assertSame(1, $this->catalog?->rowCount());
    }

    public function testAmbiguousFractionDoesNotUseSourceCapToChooseAndPreservesBothTypes(): void
    {
        $processor = $this->processor("CAP\tCOMUNE\tFRAZIONE\tPROVINCIA\tTIPO\n00100\tRovigo\tLe Grazie\tRO\tCentro abitato\n00200\tPadova\tLe Grazie\tPD\tNucleo abitato\n");
        $result = $processor->process(new AddressInput('VIA ROMA 1', '00100', 'Le Grazie', ''), false, true);

        self::assertSame(FrazioneResolutionStatus::AMBIGUOUS, $result->resolution->frazioneResolution?->status);
        self::assertSame('MISTO', $result->resolution->frazioneResolution?->typeGroup->value);
        self::assertCount(2, $result->resolution->frazioneResolution?->candidateMunicipalities);
        self::assertNotSame('00100', $result->normalizedCap());
    }

    public function testExactFractionPrecedesAnOtherwisePossibleFuzzyCityMatch(): void
    {
        $database = $this->directory . '/precedence.sqlite';
        SqliteDirectoryFixture::create($database, [
            SqliteDirectoryFixture::row('VIA ROMA', '45100', 'Rovigo', 'RO', 'T', '1', '99'),
            SqliteDirectoryFixture::row('VIA ROMA', '35122', 'Padova', 'PD', 'T', '1', '99'),
        ]);
        $catalogPath = $this->directory . '/precedence.tsv';
        file_put_contents($catalogPath, "CAP\tCOMUNE\tFRAZIONE\tPROVINCIA\tTIPO\n45100\tRovigo\tPADOV\tRO\tCentro abitato\n");
        $directory = new SqliteAddressDirectory($database);
        $cityProvider = new class implements \Normalizzatore\Directory\FuzzyCityCandidateProvider {
            public function findCityCandidates(): array
            {
                return [new \Normalizzatore\City\CityCandidate('Padova', 'PADOVA', 'PD')];
            }
        };
        $orchestrator = new AddressResolutionOrchestrator(
            new AddressStrategyClassifier(new CapizzatedCityCatalog([new CapizzatedCity('PADOVA', 'PD')])),
            new AddressParser(),
            $directory,
            new CapResolver(),
            new TerritorialResolver(),
            fuzzyCityCandidateProvider: $cityProvider,
            fuzzyCityResolver: new \Normalizzatore\City\FuzzyCityResolver(),
            frazioneCatalog: new FrazioneCatalog($catalogPath),
        );
        $processor = new AddressProcessor($orchestrator, new SourceCapVerifier(), new AddressFieldNormalizer());
        $result = $processor->process(new AddressInput('', '99999', 'PADOV', 'PD'), true, true);

        self::assertSame('NO_MATCH', $result->resolution->status->name);
        self::assertSame(FrazioneResolutionStatus::INDETERMINATE, $result->resolution->frazioneResolution?->status);
        self::assertSame('SOURCE_PROVINCE_CONFLICT', $result->resolution->frazioneResolution?->diagnostic?->value);
        self::assertSame(\Normalizzatore\City\FuzzyCityResolutionStatus::MATCH, $result->resolution->fuzzyCityResolution?->status);
        self::assertNull($result->normalizedCap());
    }

    public function testSameProvinceUniqueFractionIsAppliedEvenWhenStreetIsAbsent(): void
    {
        $processor = $this->processorFor(
            [SqliteDirectoryFixture::row('VIA ROMA', '12345', 'Target', 'AA', 'T', '1', '99')],
            "12345\tTarget\tBorgata\tAA\tCentro abitato\n",
        );
        $result = $processor->process(new AddressInput('VIA MISSING 8', '', 'Borgata', 'AA'), false, true);

        self::assertSame(FrazioneResolutionStatus::MATCH, $result->resolution->frazioneResolution?->status);
        self::assertSame('Target', $result->fieldNormalization->city->normalizedValue);
    }

    public function testInterProvinceFractionWithoutExactStreetEvidenceIsSuspended(): void
    {
        $processor = $this->processorFor(
            [SqliteDirectoryFixture::row('VIA ROMA', '12345', 'Target', 'AA', 'T', '1', '99')],
            "12345\tTarget\tBorgata\tAA\tCentro abitato\n",
        );
        $result = $processor->process(new AddressInput('VIA MISSING 8', '', 'Borgata', 'BB'), false, true);

        self::assertSame(FrazioneResolutionStatus::INDETERMINATE, $result->resolution->frazioneResolution?->status);
        self::assertSame('SOURCE_PROVINCE_CONFLICT', $result->resolution->frazioneResolution?->diagnostic?->value);
        self::assertSame(FrazioneStreetEvidenceStatus::STREET_NOT_FOUND, $result->resolution->frazioneResolution?->streetEvidence?->status);
        self::assertNull($result->fieldNormalization->city->correction);
    }

    public function testInterProvinceFractionNeverAppliesOnStreetAndCivicEvidenceAlone(): void
    {
        $processor = $this->processorFor(
            [SqliteDirectoryFixture::row('VIA ROMA', '12345', 'Target', 'AA', 'D', '1', '9')],
            "12345\tTarget\tBorgata\tAA\tCentro abitato\n",
        );
        $compatible = $processor->process(new AddressInput('VIA ROMA 3', '', 'Borgata', 'BB'), false, true);
        $incompatible = $processor->process(new AddressInput('VIA ROMA 4', '', 'Borgata', 'BB'), false, true);

        self::assertSame(FrazioneResolutionStatus::INDETERMINATE, $compatible->resolution->frazioneResolution?->status);
        self::assertSame('SOURCE_PROVINCE_CONFLICT', $compatible->resolution->frazioneResolution?->diagnostic?->value);
        self::assertSame(FrazioneStreetEvidenceStatus::CIVIC_COMPATIBLE, $compatible->resolution->frazioneResolution?->streetEvidence?->status);
        self::assertNull($compatible->fieldNormalization->city->correction);
        self::assertSame('Borgata', $compatible->fieldNormalization->city->normalizedValue);
        $differentSourceCap = $processor->process(new AddressInput('VIA ROMA 3', '99999', 'Borgata', 'BB'), false, true);
        self::assertSame($compatible->resolution->frazioneResolution?->status, $differentSourceCap->resolution->frazioneResolution?->status);
        self::assertSame($compatible->normalizedCap(), $differentSourceCap->normalizedCap());
        self::assertSame(FrazioneResolutionStatus::INDETERMINATE, $incompatible->resolution->frazioneResolution?->status);
        self::assertSame(FrazioneStreetEvidenceStatus::CIVIC_NOT_COMPATIBLE, $incompatible->resolution->frazioneResolution?->streetEvidence?->status);
        self::assertNull($incompatible->fieldNormalization->city->correction);
    }

    public function testFuzzyCityCanResolveIndependentlyAfterFractionAbstention(): void
    {
        foreach ([
            ['FOSSO', "Fosso'", '30030', 'VE'],
            ['RONCA', "Roncà", '37030', 'VR'],
        ] as [$sourceCity, $directoryCity, $cap, $province]) {
            $processor = $this->processorForWithFuzzyCity(
                [
                    SqliteDirectoryFixture::row('VIA ROMA', $cap, $directoryCity, $province, 'T', '1', '99'),
                    SqliteDirectoryFixture::row('VIA ROMA', '00100', 'Other', 'AA', 'T', '1', '99'),
                ],
                "00100\tOther\t$sourceCity\tAA\tNucleo abitato\n",
                [new CapizzatedCity($directoryCity, $province)],
                [new CityCandidate($directoryCity, (new \Normalizzatore\Directory\DirectoryKeyNormalizer())->normalize($directoryCity), $province)],
            );

            $result = $processor->process(new AddressInput('VIA ROMA 8', '99999', $sourceCity, $province), true, true);

            self::assertSame('RESOLVED', $result->resolution->status->name, $sourceCity);
            self::assertSame($directoryCity, $result->fieldNormalization->city->normalizedValue, $sourceCity);
            self::assertSame($cap, $result->normalizedCap(), $sourceCity);
            self::assertSame(\Normalizzatore\City\FuzzyCityResolutionStatus::MATCH, $result->resolution->fuzzyCityResolution?->status, $sourceCity);
            self::assertNull($result->resolution->frazioneResolution, $sourceCity);
        }
    }

    public function testCarceriAndRoncaCrossProvinceFractionsRemainAbstentionsDespiteCompatibleStreet(): void
    {
        $processor = $this->processorFor(
            [
                SqliteDirectoryFixture::row('VIA ROMA', '06050', 'Collazzone', 'PG', 'T', '1', '99'),
                SqliteDirectoryFixture::row('VIA ROMA', '24060', 'Bagnatica', 'BG', 'T', '1', '99'),
            ],
            "06050\tCollazzone\tCARCERI\tPG\tNucleo abitato\n24060\tBagnatica\tRONCA\tBG\tNucleo abitato\n",
        );

        foreach ([
            ['CARCERI', 'PD', 'Collazzone', 'PG', '06050'],
            ['RONCA', 'VR', 'Bagnatica', 'BG', '24060'],
        ] as [$fractionName, $sourceProvince, $candidateCity, $candidateProvince, $candidateCap]) {
            $result = $processor->process(new AddressInput('VIA ROMA 12', $candidateCap, $fractionName, $sourceProvince), false, true);

            self::assertSame(FrazioneResolutionStatus::INDETERMINATE, $result->resolution->frazioneResolution?->status, $fractionName);
            self::assertSame('SOURCE_PROVINCE_CONFLICT', $result->resolution->frazioneResolution?->diagnostic?->value, $fractionName);
            self::assertSame(FrazioneStreetEvidenceStatus::CIVIC_COMPATIBLE, $result->resolution->frazioneResolution?->streetEvidence?->status, $fractionName);
            self::assertSame($candidateCity, $result->resolution->frazioneResolution?->candidateMunicipalities[0]['comune'], $fractionName);
            self::assertSame($candidateProvince, $result->resolution->frazioneResolution?->candidateMunicipalities[0]['provincia'], $fractionName);
            self::assertNull($result->fieldNormalization->city->correction, $fractionName);
        }
    }

    public function testVasIncompleteProvinceAlternativeCannotResolveToLauco(): void
    {
        $processor = $this->processorFor(
            [SqliteDirectoryFixture::row('VIA ROMA', '33029', 'Lauco', 'UD', 'T', '1', '99')],
            "\t\tVAS\tBL\tCentro abitato\n33029\tLauco\tVAS\tUD\tNucleo abitato\n",
        );
        $result = $processor->process(new AddressInput('VIA ROMA 3', '32030', 'VAS', 'BL'), false, true);

        self::assertSame(FrazioneResolutionStatus::INDETERMINATE, $result->resolution->frazioneResolution?->status);
        self::assertSame('INCOMPLETE_TERRITORIAL_ALTERNATIVE', $result->resolution->frazioneResolution?->diagnostic?->value);
        self::assertNull($result->resolution->frazioneResolution?->streetEvidence);
        self::assertNull($result->fieldNormalization->city->correction);
        self::assertStringContainsString('[comune mancante]/BL', $this->diagnosticText($result));
    }

    public function testColloredoProvinceScopeSelectsTheMatchingMixedTypeCandidate(): void
    {
        $processor = $this->processorFor([
            SqliteDirectoryFixture::row('VIA CAPITELLO', '36040', 'Sossano', 'VI', 'T', '1', '99'),
            SqliteDirectoryFixture::row('VIA ROMA', '33040', 'Faedis', 'UD', 'T', '1', '99'),
        ], "36040\tSossano\tColloredo\tVI\tCentro abitato\n33040\tFaedis\tColloredo\tUD\tNucleo abitato\n");
        $result = $processor->process(new AddressInput('VIA CAPITELLO 17', '36040', 'COLLOREDO', 'VI'), false, true);

        self::assertSame(FrazioneResolutionStatus::MATCH, $result->resolution->frazioneResolution?->status);
        self::assertSame('MISTO', $result->resolution->frazioneResolution?->typeGroup->value);
        self::assertSame('Sossano', $result->fieldNormalization->city->normalizedValue);
        self::assertSame('36040', $result->normalizedCap());
    }

    public function testOlmiTvDoesNotBecomeTheCenterOnlyRoccaspinalvetiCandidate(): void
    {
        $entries = [
            ['BS', 'Chiari', 'BS'], ['BZ', 'Aldino', 'BZ'], ['VI', 'Barbarano Mossano', 'VI'],
            ['PC', 'Farini', 'PC'], ['FI', 'Borgo San Lorenzo', 'FI'], ['CH', 'Roccaspinalveti', 'CH'],
        ];
        $rows = [];
        $tsv = '';
        foreach ($entries as [$cap, $city, $province]) {
            $rows[] = SqliteDirectoryFixture::row('VIA ROMA', $cap, $city, $province, 'T', '1', '99');
            $type = $city === 'Roccaspinalveti' ? 'Centro abitato' : 'Nucleo abitato';
            $tsv .= "$cap\t$city\tOlmi\t$province\t$type\n";
        }
        $processor = $this->processorFor($rows, "CAP\tCOMUNE\tFRAZIONE\tPROVINCIA\tTIPO\n" . $tsv);
        $result = $processor->process(new AddressInput('VIA ALBINO DE GASPERI 16', '31048', 'OLMI', 'TV'), false, true);

        self::assertSame(FrazioneResolutionStatus::AMBIGUOUS, $result->resolution->frazioneResolution?->status);
        self::assertNotSame('Roccaspinalveti', $result->resolution->frazioneResolution?->comune);
        self::assertNull($result->fieldNormalization->city->correction);
    }

    public function testSingleInterProvinceOlmiCandidateWithoutStreetEvidenceIsStillSuspended(): void
    {
        $processor = $this->processorFor(
            [SqliteDirectoryFixture::row('VIA ROMA', '66050', 'Roccaspinalveti', 'CH', 'T', '1', '99')],
            "66050\tRoccaspinalveti\tOlmi\tCH\tCentro abitato\n",
        );
        $result = $processor->process(new AddressInput('VIA ALBINO DE GASPERI 16', '31048', 'OLMI', 'TV'), false, true);

        self::assertSame(FrazioneResolutionStatus::INDETERMINATE, $result->resolution->frazioneResolution?->status);
        self::assertSame('SOURCE_PROVINCE_CONFLICT', $result->resolution->frazioneResolution?->diagnostic?->value);
        self::assertSame(FrazioneStreetEvidenceStatus::STREET_NOT_FOUND, $result->resolution->frazioneResolution?->streetEvidence?->status);
        self::assertNull($result->fieldNormalization->city->correction);
    }

    public function testFuzzyFrazioneAppliesOnlyAfterExactStreetAndCivicCorroboration(): void
    {
        $processor = $this->processorFor(
            [SqliteDirectoryFixture::row('VIA ROMA', '45100', 'Rovigo', 'RO', 'T', '1', '99')],
            "45100\tRovigo\tFienil del Turco\tRO\tCentro abitato\n",
            [new CapizzatedCity('Rovigo', 'RO')],
        );
        $result = $processor->process(new AddressInput('VIA ROMA 8', '99999', 'FIENILE DEL TURCO', 'RO'), true, true);

        self::assertSame('RESOLVED', $result->resolution->status->name);
        self::assertSame('45100', $result->normalizedCap());
        self::assertSame('Rovigo', $result->fieldNormalization->city->normalizedValue);
        self::assertSame(FieldCorrectionReason::FUZZY_FRAZIONE_TO_COMUNE, $result->fieldNormalization->city->correction?->reason);
        self::assertSame(FuzzyFrazioneResolutionStatus::APPLIED, $result->resolution->fuzzyFrazioneResolution?->status);
        self::assertSame(FuzzyFrazioneResolutionStatus::APPLIED->value, $result->resolution->fuzzyFrazioneResolution?->status->value);
        self::assertSame(FrazioneStreetEvidenceStatus::CIVIC_COMPATIBLE, $result->resolution->fuzzyFrazioneResolution?->streetEvidence?->status);
        self::assertSame('MISMATCH', $result->capVerification->status->name);
    }

    public function testFuzzyFrazioneProducesSuggestionWhenStreetIsNotInCandidateComune(): void
    {
        $processor = $this->processorFor(
            [SqliteDirectoryFixture::row('VIA ROMA', '45100', 'Rovigo', 'RO', 'T', '1', '99')],
            "45100\tRovigo\tFienil del Turco\tRO\tCentro abitato\n",
            [new CapizzatedCity('Rovigo', 'RO')],
        );
        $result = $processor->process(new AddressInput('VIA SCONOSCIUTA 8', '45100', 'FIENILE DEL TURCO', 'RO'), true, true);
        $serialized = (new \Normalizzatore\Csv\AddressProcessingResultSerializer())->serialize($result);

        self::assertSame(FuzzyFrazioneResolutionStatus::SUGGESTED, $result->resolution->fuzzyFrazioneResolution?->status);
        self::assertSame('STREET_NOT_VERIFIED', $result->resolution->fuzzyFrazioneResolution?->diagnostic?->value);
        self::assertNotSame('RESOLVED', $result->resolution->status->name);
        self::assertNull($result->fieldNormalization->city->correction);
        self::assertSame('', $serialized[4]);
        self::assertStringContainsString('SUGGERIMENTO_CITTA:', $serialized[8]);
        self::assertStringContainsString('FRAZIONE_FUZZY:SUGGESTED', $serialized[9]);
        self::assertStringContainsString('FIENIL DEL TURCO', $serialized[9]);
        self::assertStringContainsString('Centro abitato', $serialized[9]);
    }

    public function testFuzzyFrazioneDoesNotApplyWithoutProvinceOrWhenCivicIsIncompatible(): void
    {
        $processor = $this->processorFor(
            [SqliteDirectoryFixture::row('VIA ROMA', '45100', 'Rovigo', 'RO', 'T', '1', '9')],
            "45100\tRovigo\tFienil del Turco\tRO\tCentro abitato\n",
            [new CapizzatedCity('Rovigo', 'RO')],
        );
        $missingProvince = $processor->process(new AddressInput('VIA ROMA 8', '', 'FIENILE DEL TURCO', ''), true, true);
        $badCivic = $processor->process(new AddressInput('VIA ROMA 20', '', 'FIENILE DEL TURCO', 'RO'), true, true);

        self::assertSame(FuzzyFrazioneResolutionStatus::SUGGESTED, $missingProvince->resolution->fuzzyFrazioneResolution?->status);
        self::assertSame('SOURCE_PROVINCE_MISSING_OR_INVALID', $missingProvince->resolution->fuzzyFrazioneResolution?->diagnostic?->value);
        self::assertNull($missingProvince->fieldNormalization->city->correction);
        self::assertSame(FuzzyFrazioneResolutionStatus::SUGGESTED, $badCivic->resolution->fuzzyFrazioneResolution?->status);
        self::assertSame('CIVIC_NOT_COMPATIBLE', $badCivic->resolution->fuzzyFrazioneResolution?->diagnostic?->value);
        self::assertNull($badCivic->fieldNormalization->city->correction);
    }

    public function testFuzzyFrazioneRequiresProvinceAgreementAndKeepsNominalAmbiguity(): void
    {
        $crossProvince = $this->processorFor(
            [SqliteDirectoryFixture::row('VIA ROMA', '45100', 'Rovigo', 'RO', 'T', '1', '99')],
            "45100\tRovigo\tFienil del Turco\tRO\tCentro abitato\n",
            [new CapizzatedCity('Rovigo', 'RO')],
        );
        $conflict = $crossProvince->process(new AddressInput('VIA ROMA 8', '', 'FIENILE DEL TURCO', 'PD'), true, true);
        self::assertSame(FuzzyFrazioneResolutionStatus::INDETERMINATE, $conflict->resolution->fuzzyFrazioneResolution?->status);
        self::assertSame('NO_PROVINCIAL_CANDIDATE', $conflict->resolution->fuzzyFrazioneResolution?->diagnostic?->value);
        self::assertNull($conflict->fieldNormalization->city->correction);

        $ambiguous = $this->processorFor(
            [SqliteDirectoryFixture::row('VIA ROMA', '45100', 'Rovigo', 'RO', 'T', '1', '99')],
            "45100\tRovigo\tFienil del Turco\tRO\tCentro abitato\n45100\tRovigo\tFienile del Turci\tRO\tNucleo abitato\n",
            [new CapizzatedCity('Rovigo', 'RO')],
        )->process(new AddressInput('VIA ROMA 8', '45100', 'FIENILE DEL TURCO', 'RO'), true, true);
        self::assertSame(FuzzyFrazioneResolutionStatus::AMBIGUOUS, $ambiguous->resolution->fuzzyFrazioneResolution?->status);
        self::assertCount(2, $ambiguous->resolution->fuzzyFrazioneResolution?->candidates);
        self::assertNull($ambiguous->fieldNormalization->city->correction);
    }

    public function testProvinceScopesNominalFractionCandidatesButDoesNotApplyWithoutStreetEvidence(): void
    {
        $processor = $this->processorFor(
            [SqliteDirectoryFixture::row('VIA DELLE CASE', '35040', 'Boara Pisani', 'PD', 'T', '1', '99')],
            "35040\tBoara Pisani\tOnari\tPD\tNucleo abitato\n"
                . "31059\tZero Branco\tOnaro\tTV\tNucleo abitato\n",
        );

        $result = $processor->process(new AddressInput('VIA INESISTENTE 8', '', 'ONARA', 'PD'), true, true);

        self::assertSame(FuzzyFrazioneResolutionStatus::SUGGESTED, $result->resolution->fuzzyFrazioneResolution?->status);
        self::assertSame('ONARI', $result->resolution->fuzzyFrazioneResolution?->selectedCandidate?->canonicalName);
        self::assertSame('PD', $result->resolution->fuzzyFrazioneResolution?->territorialResolution?->provincia);
        self::assertNull($result->fieldNormalization->city->correction);
        self::assertNotSame('RESOLVED', $result->resolution->status->name);
    }

    public function testFuzzyFrazioneDoesNotRunWhenFuzzyCityAlreadyMatches(): void
    {
        $processor = $this->processorForWithFuzzyCity(
            [SqliteDirectoryFixture::row('VIA ROMA', '35122', 'Padova', 'PD', 'T', '1', '99')],
            "35122\tPadova\tPADOVI\tPD\tNucleo abitato\n",
            [new CapizzatedCity('Padova', 'PD')],
            [new CityCandidate('Padova', 'PADOVA', 'PD')],
        );
        $result = $processor->process(new AddressInput('VIA ROMA 8', '35122', 'PADOV', 'PD'), true, true);

        self::assertSame(\Normalizzatore\City\FuzzyCityResolutionStatus::MATCH, $result->resolution->fuzzyCityResolution?->status);
        self::assertSame(FuzzyFrazioneResolutionStatus::BLOCKED, $result->resolution->fuzzyFrazioneResolution?->status);
        self::assertSame('FUZZY_CITY_PRECEDENCE', $result->resolution->fuzzyFrazioneResolution?->diagnostic?->value);
        self::assertNotSame(FieldCorrectionReason::FUZZY_FRAZIONE_TO_COMUNE, $result->fieldNormalization->city->correction?->reason);
    }

    public function testAmbiguousFuzzyCityCannotBeDisambiguatedByFuzzyFrazione(): void
    {
        $processor = $this->processorForWithFuzzyCity(
            [
                SqliteDirectoryFixture::row('VIA ROMA', '00100', 'Monta', 'PD', 'T', '1', '99'),
                SqliteDirectoryFixture::row('VIA ROMA', '00100', 'Santa', 'PD', 'T', '1', '99'),
            ],
            "00100\tMonta\tManto\tPD\tNucleo abitato\n",
            [],
            [new CityCandidate('Monta', 'MONTA', 'PD'), new CityCandidate('Santa', 'SANTA', 'PD')],
        );

        $result = $processor->process(new AddressInput('VIA ROMA 8', '', 'MANTA', 'PD'), true, true);

        self::assertSame(\Normalizzatore\City\FuzzyCityResolutionStatus::AMBIGUOUS, $result->resolution->fuzzyCityResolution?->status);
        self::assertSame(FuzzyFrazioneResolutionStatus::BLOCKED, $result->resolution->fuzzyFrazioneResolution?->status);
        self::assertSame('FUZZY_CITY_AMBIGUOUS', $result->resolution->fuzzyFrazioneResolution?->diagnostic?->value);
        self::assertNull($result->fieldNormalization->city->correction);
    }

    private function processor(string $fractionTsv): AddressProcessor
    {
        return $this->processorFor([
            SqliteDirectoryFixture::row('VIA ROMA', '45100', 'Rovigo', 'RO', 'T', '1', '99'),
            SqliteDirectoryFixture::row('VIA ROMA', '35122', 'Padova', 'PD', 'T', '1', '99'),
        ], $fractionTsv, [new CapizzatedCity('ROVIGO', 'RO')]);
    }

    /** @param list<array{vianum:string,cap:string,citta:string,pr:string,pari_dispa:string,civico_da:string,civico_a:string}> $rows
     * @param list<CapizzatedCity> $capizzatedCities
     */
    private function processorFor(array $rows, string $fractionTsv, array $capizzatedCities = []): AddressProcessor
    {
        return $this->buildProcessor($rows, $fractionTsv, $capizzatedCities);
    }

    /** @param list<CityCandidate> $cityCandidates */
    private function processorForWithFuzzyCity(array $rows, string $fractionTsv, array $capizzatedCities, array $cityCandidates): AddressProcessor
    {
        return $this->buildProcessor($rows, $fractionTsv, $capizzatedCities, $cityCandidates);
    }

    /** @param list<array{vianum:string,cap:string,citta:string,pr:string,pari_dispa:string,civico_da:string,civico_a:string}> $rows
     * @param list<CapizzatedCity> $capizzatedCities
     * @param list<CityCandidate> $cityCandidates
     */
    private function buildProcessor(array $rows, string $fractionTsv, array $capizzatedCities = [], array $cityCandidates = []): AddressProcessor
    {
        $database = $this->directory . '/directory-' . bin2hex(random_bytes(3)) . '.sqlite';
        SqliteDirectoryFixture::create($database, $rows);
        $catalogPath = $this->directory . '/frazioni.tsv';
        if (!str_starts_with($fractionTsv, "CAP\tCOMUNE\tFRAZIONE\tPROVINCIA\tTIPO\n")) {
            $fractionTsv = "CAP\tCOMUNE\tFRAZIONE\tPROVINCIA\tTIPO\n" . $fractionTsv;
        }
        file_put_contents($catalogPath, $fractionTsv);
        $directory = new SqliteAddressDirectory($database);
        $this->catalog = new FrazioneCatalog($catalogPath);
        $orchestrator = new AddressResolutionOrchestrator(
            new AddressStrategyClassifier(new CapizzatedCityCatalog($capizzatedCities)),
            new AddressParser(),
            $directory,
            new CapResolver(),
            new TerritorialResolver(),
            fuzzyCityCandidateProvider: $cityCandidates === [] ? null : new class($cityCandidates) implements \Normalizzatore\Directory\FuzzyCityCandidateProvider {
                /** @param list<CityCandidate> $candidates */
                public function __construct(private array $candidates) {}

                public function findCityCandidates(): array
                {
                    return $this->candidates;
                }
            },
            frazioneCatalog: $this->catalog,
        );
        return new AddressProcessor($orchestrator, new SourceCapVerifier(), new AddressFieldNormalizer());
    }

    private function diagnosticText(\Normalizzatore\Application\AddressProcessingResult $result): string
    {
        return (new \Normalizzatore\Csv\AddressProcessingResultSerializer())->serialize($result)[9];
    }
}
