<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Resolution;

use Normalizzatore\Address\AddressCandidate;
use Normalizzatore\Address\HouseNumber;
use Normalizzatore\Directory\DirectoryEntry;
use Normalizzatore\Resolution\CapResolution;
use Normalizzatore\Resolution\CapResolutionBasis;
use Normalizzatore\Resolution\CapResolutionDiagnostic;
use Normalizzatore\Resolution\CapResolutionStatus;
use Normalizzatore\Resolution\CapResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CapResolverTest extends TestCase
{
    private CapResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CapResolver();
    }

    public function testNoDirectoryEntriesMeansNoMatch(): void
    {
        $result = $this->resolve(15, []);

        self::assertSame(CapResolutionStatus::NO_MATCH, $result->status);
        self::assertSame(CapResolutionBasis::NONE, $result->basis);
        self::assertSame([], $result->candidateCaps);
        self::assertSame([], $result->applicableEntries);
        self::assertSame([], $result->unsupportedEntries);
        self::assertSame([], $result->diagnostics);
    }

    public function testTMatchesBothParities(): void
    {
        foreach (['15', '16'] as $number) {
            $result = $this->resolve($number, [$this->entry('T', '1', '30000', '00123')]);
            self::assertResolved('00123', $result);
        }
    }

    public function testThirtyThousandIsANormalInclusiveBoundNotAnInfinitySentinel(): void
    {
        $entry = $this->entry('T', '1', '30000', '00123');
        self::assertResolved('00123', $this->resolve(30000, [$entry]));

        $result = $this->resolve(30001, [$entry]);
        self::assertSame(CapResolutionStatus::NO_MATCH, $result->status);
        self::assertSame([], $result->candidateCaps);
    }

    #[DataProvider('parityCases')]
    public function testParityIsAppliedToTheSearchedNumber(string $code, string $number, bool $matches): void
    {
        $result = $this->resolve($number, [$this->entry($code, '1', '30000', '00123')]);

        if ($matches) {
            self::assertResolved('00123', $result);
            return;
        }

        self::assertSame(CapResolutionStatus::NO_MATCH, $result->status);
        self::assertContains(CapResolutionDiagnostic::NO_APPLICABLE_RANGE, $result->diagnostics);
    }

    public static function parityCases(): iterable
    {
        yield 'P with even' => ['P', '22', true];
        yield 'P with odd' => ['P', '21', false];
        yield 'D with odd' => ['D', '21', true];
        yield 'D with even' => ['D', '22', false];
    }

    #[DataProvider('rangeBoundaryCases')]
    public function testNumericRangeIsInclusive(string $number, bool $matches): void
    {
        $result = $this->resolve($number, [$this->entry('T', '10', '20', '00123')]);

        if ($matches) {
            self::assertResolved('00123', $result);
            return;
        }

        self::assertSame(CapResolutionStatus::NO_MATCH, $result->status);
    }

    public static function rangeBoundaryCases(): iterable
    {
        yield 'below start' => ['9', false];
        yield 'at start' => ['10', true];
        yield 'at end' => ['20', true];
        yield 'above end' => ['21', false];
    }

    #[DataProvider('nonOrdinaryCapCases')]
    public function testNonOrdinaryCapIsPreservedButNotResolved(string $cap): void
    {
        $entry = $this->entry('T', '1', '30', $cap);
        $result = $this->resolve(15, [$entry]);

        self::assertSame(CapResolutionStatus::INDETERMINATE, $result->status);
        self::assertSame(CapResolutionBasis::NONE, $result->basis);
        self::assertSame([], $result->candidateCaps);
        self::assertSame([$entry], $result->applicableEntries);
        self::assertContains(CapResolutionDiagnostic::NON_ORDINARY_CAP, $result->diagnostics);
        self::assertNull($result->resolvedCap());
    }

    public static function nonOrdinaryCapCases(): iterable
    {
        yield 'DISUS' => ['DISUS'];
        yield 'X' => ['X'];
        yield 'empty' => [''];
    }

    public function testMultipleApplicableEntriesWithSameCapAreResolvedAndPreserved(): void
    {
        $first = $this->entry('D', '1', '19', '00128', id: 10);
        $second = $this->entry('D', '1', '19', '00128', id: 11);
        $result = $this->resolve(15, [$first, $second]);

        self::assertResolved('00128', $result);
        self::assertSame([$first, $second], $result->applicableEntries);
    }

    public function testExactDuplicateEntriesAreNotRemoved(): void
    {
        $entry = $this->entry('T', '1', '30000', '00123');
        $result = $this->resolve(15, [$entry, $entry]);

        self::assertResolved('00123', $result);
        self::assertCount(2, $result->applicableEntries);
        self::assertSame([$entry, $entry], $result->applicableEntries);
    }

    public function testDifferentCapsAreAmbiguousAndSortedWithoutChoosingAWinner(): void
    {
        $laterCap = $this->entry('P', '2', '42', '25122', id: 1);
        $earlierCap = $this->entry('P', '42', '30000', '25121', id: 2);
        $result = $this->resolve(42, [$laterCap, $earlierCap]);

        self::assertSame(CapResolutionStatus::AMBIGUOUS, $result->status);
        self::assertSame(CapResolutionBasis::CIVIC_RANGE, $result->basis);
        self::assertSame(['25121', '25122'], $result->candidateCaps);
        self::assertSame([$laterCap, $earlierCap], $result->applicableEntries);
        self::assertContains(CapResolutionDiagnostic::MULTIPLE_CAPS, $result->diagnostics);
        self::assertNull($result->resolvedCap());
    }

    #[DataProvider('unsupportedParityCases')]
    public function testUnsupportedParityIsNotInterpreted(string $parity): void
    {
        $entry = $this->entry($parity, '1', '30', '00124');
        $result = $this->resolve(15, [$entry]);

        self::assertSame(CapResolutionStatus::INDETERMINATE, $result->status);
        self::assertSame([], $result->candidateCaps);
        self::assertSame([], $result->applicableEntries);
        self::assertSame([$entry], $result->unsupportedEntries);
        self::assertContains(CapResolutionDiagnostic::UNSUPPORTED_PARITY, $result->diagnostics);
    }

    public static function unsupportedParityCases(): iterable
    {
        yield 'R' => ['R'];
        yield 'KM' => ['KM'];
        yield 'lowercase km' => ['km'];
    }

    #[DataProvider('nonNumericRanges')]
    public function testNonNumericRangeIsNotParsedOrPartiallyExtracted(string $from, string $to): void
    {
        $entry = $this->entry('D', $from, $to, '00124');
        $result = $this->resolve(15, [$entry]);

        self::assertSame(CapResolutionStatus::INDETERMINATE, $result->status);
        self::assertSame([], $result->candidateCaps);
        self::assertSame([$entry], $result->unsupportedEntries);
        self::assertContains(CapResolutionDiagnostic::NON_NUMERIC_RANGE, $result->diagnostics);
    }

    public static function nonNumericRanges(): iterable
    {
        yield 'slash letter' => ['4/A', '4/A'];
        yield 'suffix letter' => ['456', '456A'];
        yield 'slash number' => ['87/34', '87/34'];
        yield 'extra detail' => ['14/1 R', '14/1 R'];
        yield 'comma numbers' => ['62,000', '62,000'];
    }

    public function testInvertedNumericRangeIsUnsupportedRatherThanReversed(): void
    {
        $entry = $this->entry('T', '20', '10', '00124');
        $result = $this->resolve(15, [$entry]);

        self::assertSame(CapResolutionStatus::INDETERMINATE, $result->status);
        self::assertSame([$entry], $result->unsupportedEntries);
        self::assertContains(CapResolutionDiagnostic::INVALID_NUMERIC_RANGE, $result->diagnostics);
        self::assertNotContains(CapResolutionDiagnostic::NON_NUMERIC_RANGE, $result->diagnostics);
    }

    public function testValidMatchWithUnsupportedParityAndDifferentCapIsIndeterminate(): void
    {
        $supported = $this->entry('D', '1', '30', '00123');
        $unsupported = $this->entry('R', '1', '30', '00124');
        $result = $this->resolve(15, [$supported, $unsupported]);

        self::assertSame(CapResolutionStatus::INDETERMINATE, $result->status);
        self::assertSame(['00123'], $result->candidateCaps);
        self::assertSame([$supported], $result->applicableEntries);
        self::assertSame([$unsupported], $result->unsupportedEntries);
        self::assertNull($result->resolvedCap());
    }

    public function testUniqueTerritorialCapCanResolveWithoutHouseNumber(): void
    {
        $first = $this->entry('T', '1', '30000', '00123', id: 1);
        $second = $this->entry('P', '2', '20', '00123', id: 2);
        $result = $this->resolve(null, [$first, $second]);

        self::assertSame(CapResolutionStatus::RESOLVED, $result->status);
        self::assertSame(CapResolutionBasis::UNIQUE_TERRITORIAL_CAP, $result->basis);
        self::assertSame(['00123'], $result->candidateCaps);
        self::assertSame([$first, $second], $result->applicableEntries);
        self::assertContains(CapResolutionDiagnostic::NO_HOUSE_NUMBER, $result->diagnostics);
    }

    public function testDuplicatesDoNotPreventUniqueTerritorialResolution(): void
    {
        $entry = $this->entry('T', '1', '30000', '00123');
        $result = $this->resolve(null, [$entry, $entry]);

        self::assertSame(CapResolutionStatus::RESOLVED, $result->status);
        self::assertSame(CapResolutionBasis::UNIQUE_TERRITORIAL_CAP, $result->basis);
        self::assertCount(2, $result->applicableEntries);
    }

    public function testMultipleTerritorialCapsWithoutHouseNumberAreAmbiguous(): void
    {
        $first = $this->entry('T', '1', '30000', '00123');
        $second = $this->entry('T', '1', '30000', '00124');
        $result = $this->resolve(null, [$first, $second]);

        self::assertSame(CapResolutionStatus::AMBIGUOUS, $result->status);
        self::assertSame(CapResolutionBasis::NONE, $result->basis);
        self::assertSame(['00123', '00124'], $result->candidateCaps);
        self::assertContains(CapResolutionDiagnostic::NO_HOUSE_NUMBER, $result->diagnostics);
        self::assertContains(CapResolutionDiagnostic::MULTIPLE_CAPS, $result->diagnostics);
    }

    public function testUnsupportedEntryPreventsUniqueTerritorialResolution(): void
    {
        $supported = $this->entry('T', '1', '30000', '00123');
        $unsupported = $this->entry('R', '1', '30000', '00124');
        $result = $this->resolve(null, [$supported, $unsupported]);

        self::assertSame(CapResolutionStatus::INDETERMINATE, $result->status);
        self::assertSame(['00123'], $result->candidateCaps);
        self::assertSame([$supported, $unsupported], $result->applicableEntries);
        self::assertSame([$unsupported], $result->unsupportedEntries);
        self::assertContains(CapResolutionDiagnostic::NO_HOUSE_NUMBER, $result->diagnostics);
        self::assertContains(CapResolutionDiagnostic::UNSUPPORTED_PARITY, $result->diagnostics);
    }

    public function testSpecialOnlyTerritorialCapWithoutHouseNumberIsIndeterminate(): void
    {
        $entry = $this->entry('T', '1', '30000', 'DISUS');
        $result = $this->resolve(null, [$entry]);

        self::assertSame(CapResolutionStatus::INDETERMINATE, $result->status);
        self::assertSame([], $result->candidateCaps);
        self::assertSame([$entry], $result->unsupportedEntries);
        self::assertContains(CapResolutionDiagnostic::NON_ORDINARY_CAP, $result->diagnostics);
    }

    #[DataProvider('trailingDetails')]
    public function testTrailingDetailCanUseBaseNumberWhenAllRangesAreNumeric(string $detail): void
    {
        $result = $this->resolve(15, [$this->entry('D', '1', '19', '00123')], $detail);

        self::assertResolved('00123', $result);
        self::assertContains(CapResolutionDiagnostic::CIVIC_DETAIL_PRESENT, $result->diagnostics);
        self::assertNotContains(CapResolutionDiagnostic::UNSUPPORTED_CIVIC_DETAIL, $result->diagnostics);
    }

    public static function trailingDetails(): iterable
    {
        yield 'internal' => ['interno 3'];
        yield 'slash suffix' => ['/A'];
    }

    public function testTrailingDetailAndNonNumericRangeAreIndeterminate(): void
    {
        $supported = $this->entry('D', '1', '19', '00123');
        $suffixRange = $this->entry('D', '15/A', '15/A', '00124');
        $result = $this->resolve(15, [$supported, $suffixRange], '/A');

        self::assertSame(CapResolutionStatus::INDETERMINATE, $result->status);
        self::assertSame(['00123'], $result->candidateCaps);
        self::assertSame([$supported], $result->applicableEntries);
        self::assertSame([$suffixRange], $result->unsupportedEntries);
        self::assertContains(CapResolutionDiagnostic::NON_NUMERIC_RANGE, $result->diagnostics);
        self::assertContains(CapResolutionDiagnostic::UNSUPPORTED_CIVIC_DETAIL, $result->diagnostics);
    }

    public function testTrailingDetailAndUnsupportedParityAreIndeterminate(): void
    {
        $valid = $this->entry('D', '1', '19', '00123');
        $unknown = $this->entry('R', '1', '30000', '00124');
        $result = $this->resolve(15, [$valid, $unknown], 'interno 3');

        self::assertSame(CapResolutionStatus::INDETERMINATE, $result->status);
        self::assertContains(CapResolutionDiagnostic::UNSUPPORTED_PARITY, $result->diagnostics);
        self::assertContains(CapResolutionDiagnostic::UNSUPPORTED_CIVIC_DETAIL, $result->diagnostics);
    }

    public function testNonOrdinaryCapIsApplicableEvidenceWhenItsRangeMatches(): void
    {
        $entry = $this->entry('T', '1', '30', 'X');
        $result = $this->resolve(15, [$entry]);

        self::assertSame([$entry], $result->applicableEntries);
        self::assertSame([], $result->unsupportedEntries);
        self::assertContains(CapResolutionDiagnostic::NON_ORDINARY_CAP, $result->diagnostics);
        self::assertSame(CapResolutionStatus::INDETERMINATE, $result->status);
    }

    public function testNonOrdinaryCapDoesNotEraseAnOrdinaryCandidateButPreventsResolution(): void
    {
        $ordinary = $this->entry('T', '1', '30', '00123', id: 1);
        $special = $this->entry('D', '1', '19', 'DISUS', id: 2);
        $result = $this->resolve(15, [$ordinary, $special]);

        self::assertSame(CapResolutionStatus::INDETERMINATE, $result->status);
        self::assertSame(['00123'], $result->candidateCaps);
        self::assertSame([$ordinary, $special], $result->applicableEntries);
        self::assertContains(CapResolutionDiagnostic::NON_ORDINARY_CAP, $result->diagnostics);
    }

    public function testNoApplicableRangeIsNoMatchWhenAllEntriesAreInterpretable(): void
    {
        $entry = $this->entry('P', '2', '20', '00123');
        $result = $this->resolve(21, [$entry]);

        self::assertSame(CapResolutionStatus::NO_MATCH, $result->status);
        self::assertSame(CapResolutionBasis::NONE, $result->basis);
        self::assertContains(CapResolutionDiagnostic::NO_APPLICABLE_RANGE, $result->diagnostics);
    }

    public function testUnsupportedHouseNumberStringIsNotPartiallyConverted(): void
    {
        $result = $this->resolve('15x', [$this->entry('T', '1', '30', '00123')]);

        self::assertSame(CapResolutionStatus::INDETERMINATE, $result->status);
        self::assertContains(CapResolutionDiagnostic::INVALID_HOUSE_NUMBER, $result->diagnostics);
        self::assertSame([], $result->applicableEntries);
    }

    public function testVeryLargeHouseNumberUsesSafeStringNumericComparison(): void
    {
        $entry = $this->entry('T', '999999999999999999999999', '1000000000000000000000001', '00123');
        $result = $this->resolve('1000000000000000000000000', [$entry]);

        self::assertResolved('00123', $result);
    }

    #[DataProvider('torinoCases')]
    public function testCorsoCastelfidardoBoundaries(string $number, string $expectedCap): void
    {
        $result = $this->resolve($number, [
            $this->entry('D', '1', '19', '10128', id: 1),
            $this->entry('D', '21', '30000', '10129', id: 2),
            $this->entry('P', '2', '20', '10128', id: 3),
            $this->entry('P', '22', '30000', '10129', id: 4),
        ]);

        self::assertResolved($expectedCap, $result);
        self::assertCount(1, $result->applicableEntries);
    }

    public static function torinoCases(): iterable
    {
        yield '19' => ['19', '10128'];
        yield '20' => ['20', '10128'];
        yield '21' => ['21', '10129'];
        yield '22' => ['22', '10129'];
    }

    #[DataProvider('romeCases')]
    public function testCirconvallazioneCorneliaBoundaries(string $number, string $expectedCap): void
    {
        $result = $this->resolve($number, [
            $this->entry('D', '1', '65', '00165', id: 1),
            $this->entry('P', '2', '96', '00165', id: 2),
            $this->entry('D', '67', '30000', '00167', id: 3),
            $this->entry('P', '98', '30000', '00167', id: 4),
        ]);

        self::assertResolved($expectedCap, $result);
        self::assertCount(1, $result->applicableEntries);
    }

    public static function romeCases(): iterable
    {
        yield '65' => ['65', '00165'];
        yield '66' => ['66', '00165'];
        yield '67' => ['67', '00167'];
        yield '96' => ['96', '00165'];
        yield '97' => ['97', '00167'];
        yield '98' => ['98', '00167'];
    }

    private function resolve(int|string|null $number, array $entries, string $detail = ''): CapResolution
    {
        $houseNumber = $number === null ? null : new HouseNumber((string) $number);

        return $this->resolver->resolve(
            new AddressCandidate('Via Roma', $houseNumber, $detail),
            $entries,
        );
    }

    private function entry(
        string $parity,
        string $from,
        string $to,
        string $cap,
        int $id = 1,
    ): DirectoryEntry {
        return new DirectoryEntry($id, 'VIA ROMA', $cap, 'ROMA', 'RM', $parity, $from, $to);
    }

    private static function assertResolved(string $cap, CapResolution $result): void
    {
        self::assertSame(CapResolutionStatus::RESOLVED, $result->status);
        self::assertSame([$cap], $result->candidateCaps);
        self::assertSame($cap, $result->resolvedCap());
        self::assertTrue($result->isResolved());
    }
}
