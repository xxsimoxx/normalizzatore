<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Frazione;

use Normalizzatore\Directory\DirectoryKeyNormalizer;
use Normalizzatore\Frazione\FrazioneCatalog;
use Normalizzatore\Frazione\FrazioneEntry;
use Normalizzatore\Frazione\FrazioneResolutionStatus;
use Normalizzatore\Frazione\FrazioneResolver;
use Normalizzatore\Frazione\FrazioneTypeGroup;
use PHPUnit\Framework\TestCase;

final class FrazioneResolverTest extends TestCase
{
    public function testCanonicalExactNameCanResolveCentroAbitatoAndRetainsTypeEvidence(): void
    {
        $result = (new FrazioneResolver())->resolve('Fienil del Turco', 'RO', [
            new FrazioneEntry('45100', 'Rovigo', 'FIENIL DEL TURCO', 'RO', 'Centro abitato', 2),
        ], [['comune' => 'ROVIGO', 'provincia' => 'RO']]);

        self::assertSame(FrazioneResolutionStatus::MATCH, $result->status);
        self::assertSame('ROVIGO', $result->comune);
        self::assertSame('RO', $result->provincia);
        self::assertSame(FrazioneTypeGroup::CENTRO_ABITATO, $result->typeGroup);
        self::assertSame('45100', $result->entries[0]->cap);
    }

    public function testUniqueNucleoAbitatoIsAllowedAndWrongProvinceDoesNotBlockUniqueDirectoryPair(): void
    {
        $result = (new FrazioneResolver())->resolve('Borgata', 'XX', [
            new FrazioneEntry('', 'Rovigo', 'Borgata', 'RO', 'Nucleo abitato', 4),
        ], [['comune' => 'Rovigo', 'provincia' => 'RO']]);

        self::assertSame(FrazioneResolutionStatus::MATCH, $result->status);
        self::assertSame(FrazioneTypeGroup::NUCLEO_ABITATO, $result->typeGroup);
    }

    public function testSameNameAcrossMunicipalitiesRemainsAmbiguousRegardlessOfTypeOrOrder(): void
    {
        $entries = [
            new FrazioneEntry('', 'Rovigo', 'Le Grazie', 'RO', 'Centro abitato', 2),
            new FrazioneEntry('', 'Padova', 'Le Grazie', 'PD', 'Nucleo abitato', 3),
        ];
        $pairs = [['comune' => 'Rovigo', 'provincia' => 'RO'], ['comune' => 'Padova', 'provincia' => 'PD']];
        $resolver = new FrazioneResolver();

        $first = $resolver->resolve('Le Grazie', '', $entries, $pairs);
        $second = $resolver->resolve('Le Grazie', '', array_reverse($entries), array_reverse($pairs));

        self::assertSame(FrazioneResolutionStatus::AMBIGUOUS, $first->status);
        self::assertSame(FrazioneTypeGroup::MIXED, $first->typeGroup);
        self::assertSame($first->candidateMunicipalities, $second->candidateMunicipalities);
    }

    public function testSameMunicipalityPresentAsBothTypesResolvesWithoutTypePreference(): void
    {
        $result = (new FrazioneResolver())->resolve('Santa Lucia', '', [
            new FrazioneEntry('00100', 'Roma', 'Santa Lucia', 'RM', 'Centro abitato', 2),
            new FrazioneEntry('00100', 'ROMA', 'SANTA LUCIA', 'RM', 'Nucleo abitato', 3),
        ], [['comune' => 'ROMA', 'provincia' => 'RM']]);

        self::assertSame(FrazioneResolutionStatus::MATCH, $result->status);
        self::assertSame(FrazioneTypeGroup::MIXED, $result->typeGroup);
        self::assertCount(2, $result->entries);
    }

    public function testProvinceCanScopeButSourceCapIsNotPartOfResolverContract(): void
    {
        $entries = [
            new FrazioneEntry('99999', 'Rovigo', 'San Pietro', 'RO', 'Centro abitato', 2),
            new FrazioneEntry('00100', 'Padova', 'San Pietro', 'PD', 'Nucleo abitato', 3),
        ];
        $result = (new FrazioneResolver())->resolve('San Pietro', 'RO', $entries, [
            ['comune' => 'Rovigo', 'provincia' => 'RO'],
            ['comune' => 'Padova', 'provincia' => 'PD'],
        ]);

        self::assertSame(FrazioneResolutionStatus::MATCH, $result->status);
        self::assertSame('Rovigo', $result->comune);
    }

    public function testUnverifiedMissingMunicipalityIsIndeterminateAndMissingCatalogNameIsNoMatch(): void
    {
        $resolver = new FrazioneResolver();
        $missingMunicipality = $resolver->resolve('Case Nuove', null, [
            new FrazioneEntry('', '', 'Case Nuove', 'SS', 'Nucleo abitato', 9),
        ], []);
        $missingName = $resolver->resolve('unknown', null, [], []);

        self::assertSame(FrazioneResolutionStatus::INDETERMINATE, $missingMunicipality->status);
        self::assertSame(FrazioneResolutionStatus::NO_MATCH, $missingName->status);
        self::assertSame(FrazioneTypeGroup::NUCLEO_ABITATO, $missingMunicipality->typeGroup);
    }

    public function testIncompleteAlternativePreventsUniqueVerifiedCandidate(): void
    {
        $result = (new FrazioneResolver())->resolve('VAS', 'BL', [
            new FrazioneEntry('', '', 'VAS', 'BL', 'Centro abitato', 2),
            new FrazioneEntry('33029', 'Lauco', 'VAS', 'UD', 'Nucleo abitato', 3),
        ], [['comune' => 'Lauco', 'provincia' => 'UD']]);

        self::assertSame(FrazioneResolutionStatus::INDETERMINATE, $result->status);
        self::assertSame('INCOMPLETE_TERRITORIAL_ALTERNATIVE', $result->diagnostic?->value);
        self::assertSame(FrazioneTypeGroup::MIXED, $result->typeGroup);
        self::assertCount(2, $result->entries);
        self::assertCount(1, $result->candidateMunicipalities);
        self::assertCount(1, $result->incompleteAlternatives);
        self::assertSame(2, $result->incompleteAlternatives[0]->lineNumber);
    }

    public function testProvinceDoesNotChooseBetweenTwoMunicipalitiesInSameProvince(): void
    {
        $result = (new FrazioneResolver())->resolve('Borgata', 'AA', [
            new FrazioneEntry('10000', 'Alpha', 'Borgata', 'AA', 'Centro abitato', 2),
            new FrazioneEntry('10001', 'Beta', 'Borgata', 'AA', 'Nucleo abitato', 3),
        ], [['comune' => 'Alpha', 'provincia' => 'AA'], ['comune' => 'Beta', 'provincia' => 'AA']]);

        self::assertSame(FrazioneResolutionStatus::AMBIGUOUS, $result->status);
        self::assertCount(2, $result->candidateMunicipalities);
    }

    public function testCatalogLoadsLazilyAndCanonicalizesNames(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'frazioni-');
        self::assertNotFalse($path);
        file_put_contents($path, "CAP\tCOMUNE\tFRAZIONE\tPROVINCIA\tTIPO\n00100\tRoma\tSant’Agata\tRM\tCentro abitato\n");
        try {
            $catalog = new FrazioneCatalog($path, new DirectoryKeyNormalizer());
            self::assertFalse($catalog->isLoaded());
            $found = $catalog->find("SANT'AGATA");
            self::assertTrue($catalog->isLoaded());
            self::assertSame(1, $catalog->rowCount());
            self::assertCount(1, $found);
            self::assertSame('Centro abitato', $found[0]->tipo);
        } finally {
            unlink($path);
        }
    }

    public function testTypeIsPreservedEvenWhenUnrecognized(): void
    {
        $result = (new FrazioneResolver())->resolve('Foo', null, [
            new FrazioneEntry('', 'Roma', 'Foo', 'RM', 'Tipo futuro', 7),
        ], [['comune' => 'Roma', 'provincia' => 'RM']]);

        self::assertSame(FrazioneResolutionStatus::MATCH, $result->status);
        self::assertSame(FrazioneTypeGroup::UNKNOWN, $result->typeGroup);
        self::assertSame('Tipo futuro', $result->entries[0]->tipo);
    }
}
