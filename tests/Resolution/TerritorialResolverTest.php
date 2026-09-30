<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Resolution;

use Normalizzatore\Directory\TerritorialEntry;
use Normalizzatore\Resolution\TerritorialResolutionDiagnostic;
use Normalizzatore\Resolution\TerritorialResolutionStatus;
use Normalizzatore\Resolution\TerritorialResolver;
use PHPUnit\Framework\TestCase;

final class TerritorialResolverTest extends TestCase
{
    private TerritorialResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new TerritorialResolver();
    }

    public function testNoEvidenceReturnsNoMatch(): void
    {
        $result = $this->resolver->resolve([]);

        self::assertSame(TerritorialResolutionStatus::NO_MATCH, $result->status);
        self::assertSame([], $result->candidateCaps);
        self::assertNull($result->resolvedCap);
        self::assertSame([], $result->evidence);
    }

    public function testOneOrdinaryCapResolvesAndPreservesLeadingZeroes(): void
    {
        $result = $this->resolver->resolve([$this->entry('ROMA', 'RM', '00100')]);

        self::assertSame(TerritorialResolutionStatus::RESOLVED, $result->status);
        self::assertSame(['00100'], $result->candidateCaps);
        self::assertSame('00100', $result->resolvedCap);
    }

    public function testRepeatedEvidenceWithSameCapResolvesAndIsPreserved(): void
    {
        $entries = [
            $this->entry('SAN TEODORO', 'OT', '07052', 12),
            $this->entry('SAN TEODORO', 'OT', '07052', 4),
            $this->entry('SAN TEODORO', 'OT', '07052', 12),
        ];
        $result = $this->resolver->resolve($entries);

        self::assertSame(TerritorialResolutionStatus::RESOLVED, $result->status);
        self::assertSame(['07052'], $result->candidateCaps);
        self::assertCount(3, $result->evidence);
        self::assertSame('07052', $result->resolvedCap);
    }

    public function testDifferentCapsAreAmbiguousAcrossProvinces(): void
    {
        $result = $this->resolver->resolve([
            $this->entry('SAN TEODORO', 'OT', '07052'),
            $this->entry('SAN TEODORO', 'ME', '98030'),
        ]);

        self::assertSame(TerritorialResolutionStatus::AMBIGUOUS, $result->status);
        self::assertSame(['07052', '98030'], $result->candidateCaps);
        self::assertNull($result->resolvedCap);
        self::assertCount(2, $result->evidence);
        self::assertContains(TerritorialResolutionDiagnostic::MULTIPLE_ORDINARY_CAPS, $result->diagnostics);
    }

    public function testSameCapAcrossProvincesResolvesAndKeepsBothEvidenceRows(): void
    {
        $result = $this->resolver->resolve([
            $this->entry('TINNURA', 'OR', '08010'),
            $this->entry('TINNURA', 'NU', '08010'),
        ]);

        self::assertSame(TerritorialResolutionStatus::RESOLVED, $result->status);
        self::assertSame('08010', $result->resolvedCap);
        self::assertCount(2, $result->evidence);
    }

    public function testInputOrderDoesNotChangeResolutionOrEvidenceOrder(): void
    {
        $first = $this->entry('SAMONE', 'TO', '10010');
        $second = $this->entry('SAMONE', 'TN', '38059');
        $forward = $this->resolver->resolve([$first, $second]);
        $reverse = $this->resolver->resolve([$second, $first]);

        self::assertSame($forward->status, $reverse->status);
        self::assertSame($forward->candidateCaps, $reverse->candidateCaps);
        self::assertSame($forward->evidence, $reverse->evidence);
    }

    public function testOnlyDisusIsIndeterminate(): void
    {
        $result = $this->resolver->resolve([$this->entry('BASTIA UMBRA', 'PG', 'DISUS')]);

        self::assertSame(TerritorialResolutionStatus::INDETERMINATE, $result->status);
        self::assertSame([], $result->candidateCaps);
        self::assertNull($result->resolvedCap);
        self::assertContains(TerritorialResolutionDiagnostic::NON_ORDINARY_CAP, $result->diagnostics);
    }

    public function testOnlyXIsIndeterminate(): void
    {
        $result = $this->resolver->resolve([$this->entry('PESARO-URBINO', 'PU', 'X')]);

        self::assertSame(TerritorialResolutionStatus::INDETERMINATE, $result->status);
        self::assertNull($result->resolvedCap);
    }

    public function testOnlyEmptyCapIsIndeterminate(): void
    {
        $result = $this->resolver->resolve([$this->entry('CITTA', 'XX', '')]);

        self::assertSame(TerritorialResolutionStatus::INDETERMINATE, $result->status);
        self::assertNull($result->resolvedCap);
        self::assertContains(TerritorialResolutionDiagnostic::NON_ORDINARY_CAP, $result->diagnostics);
    }

    public function testOrdinaryCapAlongsideSpecialCapIsIndeterminateAndRetainsCandidate(): void
    {
        $result = $this->resolver->resolve([
            $this->entry('FORLI\'-CESENA', 'FC', '47121'),
            $this->entry('FORLI\'-CESENA', 'FC', 'X'),
        ]);

        self::assertSame(TerritorialResolutionStatus::INDETERMINATE, $result->status);
        self::assertSame(['47121'], $result->candidateCaps);
        self::assertNull($result->resolvedCap);
        self::assertContains(TerritorialResolutionDiagnostic::SPECIAL_CAP_WITH_ORDINARY_CAP, $result->diagnostics);
        self::assertCount(2, $result->evidence);
    }

    public function testMultipleOrdinaryCapsRemainAmbiguousEvenWhenSpecialEvidenceExists(): void
    {
        $result = $this->resolver->resolve([
            $this->entry('FORLI\'-CESENA', 'FC', '47121'),
            $this->entry('FORLI\'-CESENA', 'FC', '47521'),
            $this->entry('FORLI\'-CESENA', 'FC', 'X'),
        ]);

        self::assertSame(TerritorialResolutionStatus::AMBIGUOUS, $result->status);
        self::assertSame(['47121', '47521'], $result->candidateCaps);
        self::assertNull($result->resolvedCap);
        self::assertContains(TerritorialResolutionDiagnostic::NON_ORDINARY_CAP, $result->diagnostics);
        self::assertContains(TerritorialResolutionDiagnostic::MULTIPLE_ORDINARY_CAPS, $result->diagnostics);
    }

    public function testUnexpectedNonOrdinaryCapIsPreservedAsEvidence(): void
    {
        $entry = $this->entry('CITTA', 'XX', 'CAP?');
        $result = $this->resolver->resolve([$entry]);

        self::assertSame(TerritorialResolutionStatus::INDETERMINATE, $result->status);
        self::assertSame($entry, $result->evidence[0]);
        self::assertSame([], $result->candidateCaps);
    }

    public function testResolvedCapIsOnlyExposedForResolvedStatus(): void
    {
        $ambiguous = $this->resolver->resolve([
            $this->entry('SAMONE', 'TO', '10010'),
            $this->entry('SAMONE', 'TN', '38059'),
        ]);
        $indeterminate = $this->resolver->resolve([$this->entry('LUNI', 'SP', 'DISUS')]);

        self::assertNull($ambiguous->resolvedCap);
        self::assertNull($indeterminate->resolvedCap);
        self::assertTrue($this->resolver->resolve([$this->entry('TINNURA', 'OR', '08010')])->isResolved());
    }

    private function entry(string $city, string $province, string $cap, int $recordCount = 1): TerritorialEntry
    {
        return new TerritorialEntry($city, $province, $cap, $recordCount);
    }
}
