<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\City;

use Normalizzatore\City\CityCandidate;
use Normalizzatore\City\FuzzyCityResolutionStatus;
use Normalizzatore\City\FuzzyCityResolver;
use PHPUnit\Framework\TestCase;

final class FuzzyCityResolverTest extends TestCase
{
    public function testUniqueOneEditCityMatchCarriesDistanceEvidence(): void
    {
        $result = (new FuzzyCityResolver())->resolve('PADVOA', null, [
            new CityCandidate('PADOVA', 'PADOVA', 'PD'),
            new CityCandidate('PARMA', 'PARMA', 'PR'),
        ]);

        self::assertSame(FuzzyCityResolutionStatus::MATCH, $result->status);
        self::assertSame('PADOVA', $result->match?->candidate->name);
        self::assertSame(1, $result->match?->distance);
        self::assertSame(3, $result->match?->secondBestDistance);
        self::assertSame(2, $result->match?->margin);
    }

    public function testEqualBestCitiesRemainAmbiguousRegardlessOfCandidateOrder(): void
    {
        $candidates = [
            new CityCandidate('MONTA', 'MONTA', 'PD'),
            new CityCandidate('SANTA', 'SANTA', 'RO'),
        ];
        $resolver = new FuzzyCityResolver();

        $forward = $resolver->resolve('MANTA', null, $candidates);
        $reverse = $resolver->resolve('MANTA', null, array_reverse($candidates));

        self::assertSame(FuzzyCityResolutionStatus::AMBIGUOUS, $forward->status);
        self::assertSame(FuzzyCityResolutionStatus::AMBIGUOUS, $reverse->status);
        self::assertSame(['MONTA', 'SANTA'], array_map(static fn (CityCandidate $city): string => $city->name, $forward->ambiguousCandidates));
        self::assertSame(['MONTA', 'SANTA'], array_map(static fn (CityCandidate $city): string => $city->name, $reverse->ambiguousCandidates));
    }

    public function testShortNameIsNotApplicableAndProvinceDisambiguatesSameCityKey(): void
    {
        $resolver = new FuzzyCityResolver();
        self::assertSame(FuzzyCityResolutionStatus::NOT_APPLICABLE, $resolver->resolve('ROM', null, [])->status);

        $result = $resolver->resolve('OMEGAA', 'PD', [
            new CityCandidate('OMEGA', 'OMEGA', 'RO'),
            new CityCandidate('OMEGA', 'OMEGA', 'PD'),
        ]);
        self::assertSame(FuzzyCityResolutionStatus::MATCH, $result->status);
        self::assertSame('PD', $result->match?->candidate->province);
        self::assertTrue($result->match?->provinceScoped);
    }

    public function testProvinceScopesNearbyCityCandidatesBeforeScoring(): void
    {
        $result = (new FuzzyCityResolver())->resolve('MANTA', 'PD', [
            new CityCandidate('SANTA', 'SANTA', 'RO'),
            new CityCandidate('MONTA', 'MONTA', 'PD'),
        ]);

        self::assertSame(FuzzyCityResolutionStatus::MATCH, $result->status);
        self::assertSame('MONTA', $result->match?->candidate->name);
        self::assertTrue($result->match?->provinceScoped);
    }

    public function testExactCanonicalCandidateIsNeverReplacedByFuzzyMatching(): void
    {
        $result = (new FuzzyCityResolver())->resolve('PADOVA', 'PD', [
            new CityCandidate('PADOVA', 'PADOVA', 'PD'),
            new CityCandidate('PADOVA', 'PADOVA', 'RO'),
        ]);

        self::assertSame(FuzzyCityResolutionStatus::NOT_APPLICABLE, $result->status);
    }
}
