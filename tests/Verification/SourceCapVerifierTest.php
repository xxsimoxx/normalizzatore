<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Verification;

use Normalizzatore\Address\AddressResolutionStrategy;
use Normalizzatore\Resolution\AddressResolution;
use Normalizzatore\Resolution\AddressResolutionDiagnostic;
use Normalizzatore\Resolution\AddressResolutionStatus;
use Normalizzatore\Resolution\TerritorialResolution;
use Normalizzatore\Resolution\TerritorialResolutionStatus;
use Normalizzatore\Verification\SourceCapCorrectionReason;
use Normalizzatore\Verification\SourceCapVerificationDiagnostic;
use Normalizzatore\Verification\SourceCapVerificationStatus;
use Normalizzatore\Verification\SourceCapVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SourceCapVerifierTest extends TestCase
{
    private SourceCapVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new SourceCapVerifier();
    }

    public function testValidMatchingCapPreservesLeadingZeroesAndSuggestsNoCorrection(): void
    {
        $resolution = $this->resolved('00100');
        $verification = $this->verifier->verify('00100', $resolution);

        self::assertSame(SourceCapVerificationStatus::MATCH, $verification->status);
        self::assertSame('00100', $verification->sourceCapOriginal);
        self::assertSame('00100', $verification->sourceCapForComparison);
        self::assertSame('00100', $verification->resolvedCap);
        self::assertSame(['00100'], $verification->candidateCaps);
        self::assertTrue($verification->sourceCapIsCandidate);
        self::assertNull($verification->suggestedCorrection);
        self::assertSame([], $verification->diagnostics);
    }

    public function testValidDifferentCapIsMismatchWithStructuredCorrection(): void
    {
        $verification = $this->verifier->verify('00101', $this->resolved('00100'));

        self::assertSame(SourceCapVerificationStatus::MISMATCH, $verification->status);
        self::assertSame('00101', $verification->sourceCapOriginal);
        self::assertSame('00100', $verification->resolvedCap);
        self::assertFalse($verification->sourceCapIsCandidate);
        self::assertSame(SourceCapVerificationDiagnostic::SOURCE_CAP_DIFFERS, $verification->diagnostics[0]);
        self::assertNotNull($verification->suggestedCorrection);
        self::assertSame('00101', $verification->suggestedCorrection->sourceCapOriginal);
        self::assertSame('00100', $verification->suggestedCorrection->proposedCap);
        self::assertSame(SourceCapCorrectionReason::SOURCE_CAP_MISMATCH, $verification->suggestedCorrection->reason);
    }

    #[DataProvider('missingCapCases')]
    public function testMissingCapCanSuggestResolvedCap(string $sourceCap): void
    {
        $verification = $this->verifier->verify($sourceCap, $this->resolved('00100'));

        self::assertSame(SourceCapVerificationStatus::SOURCE_MISSING, $verification->status);
        self::assertSame($sourceCap, $verification->sourceCapOriginal);
        self::assertNull($verification->sourceCapForComparison);
        self::assertNull($verification->sourceCapIsCandidate);
        self::assertSame(SourceCapVerificationDiagnostic::SOURCE_CAP_MISSING, $verification->diagnostics[0]);
        self::assertSame('00100', $verification->suggestedCorrection?->proposedCap);
        self::assertSame(SourceCapCorrectionReason::SOURCE_CAP_MISSING, $verification->suggestedCorrection?->reason);
    }

    public static function missingCapCases(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => [" \t\n "];
    }

    #[DataProvider('invalidCapCases')]
    public function testInvalidSourceCapIsNotRepairedButMayHaveAResolvedSuggestion(string $sourceCap): void
    {
        $verification = $this->verifier->verify($sourceCap, $this->resolved('00100'));

        self::assertSame(SourceCapVerificationStatus::SOURCE_INVALID, $verification->status);
        self::assertSame($sourceCap, $verification->sourceCapOriginal);
        self::assertNull($verification->sourceCapForComparison);
        self::assertNull($verification->sourceCapIsCandidate);
        self::assertSame(SourceCapVerificationDiagnostic::SOURCE_CAP_INVALID, $verification->diagnostics[0]);
        self::assertSame($sourceCap, $verification->suggestedCorrection?->sourceCapOriginal);
        self::assertSame('00100', $verification->suggestedCorrection?->proposedCap);
        self::assertSame(SourceCapCorrectionReason::SOURCE_CAP_INVALID, $verification->suggestedCorrection?->reason);
    }

    public static function invalidCapCases(): iterable
    {
        yield 'internal whitespace' => ['00 100'];
        yield 'letters' => ['00A00'];
        yield 'four digits' => ['0100'];
        yield 'six digits' => ['000100'];
        yield 'DISUS' => ['DISUS'];
        yield 'X' => ['X'];
    }

    public function testOnlyOuterWhitespaceIsTrimmedForComparison(): void
    {
        $verification = $this->verifier->verify(" \t00100\n", $this->resolved('00100'));

        self::assertSame(SourceCapVerificationStatus::MATCH, $verification->status);
        self::assertSame(" \t00100\n", $verification->sourceCapOriginal);
        self::assertSame('00100', $verification->sourceCapForComparison);
    }

    public function testNonWhitespaceControlCharactersAreNotTrimmedIntoAValidCap(): void
    {
        $verification = $this->verifier->verify("\0" . '00100', $this->resolved('00100'));

        self::assertSame(SourceCapVerificationStatus::SOURCE_INVALID, $verification->status);
        self::assertNull($verification->sourceCapForComparison);
        self::assertSame("\0" . '00100', $verification->sourceCapOriginal);
    }

    public function testInvalidCapWithoutUniqueResolutionHasNoCorrection(): void
    {
        foreach ([$this->ambiguous(['00100', '00200']), $this->indeterminate(['00100']), $this->noMatch()] as $resolution) {
            $verification = $this->verifier->verify('DISUS', $resolution);
            self::assertSame(SourceCapVerificationStatus::SOURCE_INVALID, $verification->status);
            self::assertNull($verification->suggestedCorrection);
        }
    }

    public function testMissingCapWithoutUniqueResolutionHasNoCorrection(): void
    {
        foreach ([$this->ambiguous(['00100', '00200']), $this->indeterminate(['00100']), $this->noMatch()] as $resolution) {
            $verification = $this->verifier->verify('', $resolution);
            self::assertSame(SourceCapVerificationStatus::SOURCE_MISSING, $verification->status);
            self::assertNull($verification->suggestedCorrection);
        }
    }

    public function testValidCapCannotResolveNoMatchAmbiguousOrIndeterminateAddress(): void
    {
        foreach ([$this->noMatch(), $this->ambiguous(['00100', '00200']), $this->indeterminate(['00100'])] as $resolution) {
            $verification = $this->verifier->verify('00100', $resolution);
            self::assertSame(SourceCapVerificationStatus::UNVERIFIABLE, $verification->status);
            self::assertNull($verification->resolvedCap);
            self::assertNull($verification->suggestedCorrection);
            self::assertContains(SourceCapVerificationDiagnostic::RESOLUTION_UNVERIFIABLE, $verification->diagnostics);
        }
    }

    public function testValidSourceCapMembershipInAmbiguousCandidatesIsDiagnosticOnly(): void
    {
        $resolution = $this->ambiguous(['00100', '00200']);
        $before = serialize($resolution);
        $verification = $this->verifier->verify('00200', $resolution);

        self::assertSame(SourceCapVerificationStatus::UNVERIFIABLE, $verification->status);
        self::assertTrue($verification->sourceCapIsCandidate);
        self::assertSame(['00100', '00200'], $verification->candidateCaps);
        self::assertContains(SourceCapVerificationDiagnostic::SOURCE_CAP_PRESENT_AMONG_CANDIDATES, $verification->diagnostics);
        self::assertNull($verification->suggestedCorrection);
        self::assertSame(AddressResolutionStatus::AMBIGUOUS, $resolution->status);
        self::assertSame($before, serialize($resolution));
    }

    public function testValidSourceCapAbsentFromCandidatesDoesNotSelectOrCorrect(): void
    {
        $verification = $this->verifier->verify('00300', $this->ambiguous(['00100', '00200']));

        self::assertSame(SourceCapVerificationStatus::UNVERIFIABLE, $verification->status);
        self::assertFalse($verification->sourceCapIsCandidate);
        self::assertContains(SourceCapVerificationDiagnostic::SOURCE_CAP_ABSENT_FROM_CANDIDATES, $verification->diagnostics);
        self::assertNull($verification->suggestedCorrection);
    }

    public function testMissingAndInvalidStatusesTakePrecedenceOverUnresolvedResolution(): void
    {
        $ambiguous = $this->ambiguous(['00100', '00200']);

        self::assertSame(SourceCapVerificationStatus::SOURCE_MISSING, $this->verifier->verify(' ', $ambiguous)->status);
        self::assertSame(SourceCapVerificationStatus::SOURCE_INVALID, $this->verifier->verify('X', $ambiguous)->status);
    }

    public function testVerificationCopiesOnlyResolutionSummaryAndDoesNotChangeIt(): void
    {
        $resolution = $this->resolved('00100');
        $before = [
            $resolution->strategy,
            $resolution->status,
            $resolution->resolvedCap,
            $resolution->candidateCaps,
            $resolution->diagnostics,
            $resolution->streetCandidateResolutions,
        ];

        $verification = $this->verifier->verify('00999', $resolution);

        self::assertSame($before, [
            $resolution->strategy,
            $resolution->status,
            $resolution->resolvedCap,
            $resolution->candidateCaps,
            $resolution->diagnostics,
            $resolution->streetCandidateResolutions,
        ]);
        self::assertSame(AddressResolutionStatus::RESOLVED, $verification->resolutionStatus);
        self::assertSame($resolution->candidateCaps, $verification->candidateCaps);
        self::assertSame('00100', $verification->resolvedCap);
    }

    private function resolved(string $cap): AddressResolution
    {
        return new AddressResolution(
            AddressResolutionStrategy::TERRITORIAL,
            AddressResolutionStatus::RESOLVED,
            [$cap],
            $cap,
            new TerritorialResolution(TerritorialResolutionStatus::RESOLVED, [$cap], $cap, [], []),
            [],
            [],
        );
    }

    /** @param list<string> $caps */
    private function ambiguous(array $caps): AddressResolution
    {
        return new AddressResolution(
            AddressResolutionStrategy::TERRITORIAL,
            AddressResolutionStatus::AMBIGUOUS,
            $caps,
            null,
            new TerritorialResolution(TerritorialResolutionStatus::AMBIGUOUS, $caps, null, [], []),
            [],
            [AddressResolutionDiagnostic::MULTIPLE_TERRITORIAL_CAPS],
        );
    }

    /** @param list<string> $caps */
    private function indeterminate(array $caps): AddressResolution
    {
        $evidence = [new \Normalizzatore\Directory\TerritorialEntry('CITY', 'XX', 'DISUS', 1)];

        return new AddressResolution(
            AddressResolutionStrategy::TERRITORIAL,
            AddressResolutionStatus::INDETERMINATE,
            $caps,
            null,
            new TerritorialResolution(TerritorialResolutionStatus::INDETERMINATE, $caps, null, $evidence, []),
            [],
            [AddressResolutionDiagnostic::INDETERMINATE_EVIDENCE],
        );
    }

    private function noMatch(): AddressResolution
    {
        return new AddressResolution(
            AddressResolutionStrategy::TERRITORIAL,
            AddressResolutionStatus::NO_MATCH,
            [],
            null,
            new TerritorialResolution(TerritorialResolutionStatus::NO_MATCH, [], null, [], []),
            [],
            [AddressResolutionDiagnostic::NO_TERRITORIAL_MATCH],
        );
    }
}
