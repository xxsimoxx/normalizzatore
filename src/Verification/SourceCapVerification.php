<?php

declare(strict_types=1);

namespace Normalizzatore\Verification;

use InvalidArgumentException;
use Normalizzatore\Resolution\AddressResolutionStatus;

final readonly class SourceCapVerification
{
    /**
     * @param list<string> $candidateCaps
     * @param list<SourceCapVerificationDiagnostic> $diagnostics
     */
    public function __construct(
        public string $sourceCapOriginal,
        public ?string $sourceCapForComparison,
        public SourceCapVerificationStatus $status,
        public AddressResolutionStatus $resolutionStatus,
        public ?string $resolvedCap,
        public array $candidateCaps,
        public ?bool $sourceCapIsCandidate,
        public ?SourceCapCorrection $suggestedCorrection,
        public array $diagnostics,
    ) {
        if (!array_is_list($candidateCaps) || !array_is_list($diagnostics)) {
            throw new InvalidArgumentException('Source CAP verification collections must be lists.');
        }

        $sortedCaps = $candidateCaps;
        sort($sortedCaps, SORT_STRING);
        if ($candidateCaps !== $sortedCaps || count(array_unique($candidateCaps)) !== count($candidateCaps)) {
            throw new InvalidArgumentException('Candidate CAPs must be unique and sorted lexicographically.');
        }
        foreach ($candidateCaps as $cap) {
            if (preg_match('/\A[0-9]{5}\z/', $cap) !== 1) {
                throw new InvalidArgumentException('Candidate CAPs must contain exactly five ASCII digits.');
            }
        }
        foreach ($diagnostics as $diagnostic) {
            if (!$diagnostic instanceof SourceCapVerificationDiagnostic) {
                throw new InvalidArgumentException('Verification diagnostics must use SourceCapVerificationDiagnostic values.');
            }
        }
        if (count(array_unique(array_map(static fn (SourceCapVerificationDiagnostic $item): string => $item->value, $diagnostics))) !== count($diagnostics)) {
            throw new InvalidArgumentException('Verification diagnostics must be unique.');
        }

        if ($sourceCapForComparison !== null && preg_match('/\A[0-9]{5}\z/', $sourceCapForComparison) !== 1) {
            throw new InvalidArgumentException('The comparison CAP must contain exactly five ASCII digits.');
        }
        if ($sourceCapIsCandidate !== null && $sourceCapForComparison === null) {
            throw new InvalidArgumentException('Candidate membership can only be checked for a valid source CAP.');
        }
        if ($sourceCapForComparison !== null
            && $sourceCapIsCandidate !== in_array($sourceCapForComparison, $candidateCaps, true)) {
            throw new InvalidArgumentException('Source CAP candidate membership must match the candidate CAP list.');
        }
        if ($resolvedCap !== null && preg_match('/\A[0-9]{5}\z/', $resolvedCap) !== 1) {
            throw new InvalidArgumentException('The resolved CAP must contain exactly five ASCII digits.');
        }
        if (($resolutionStatus === AddressResolutionStatus::RESOLVED) !== ($resolvedCap !== null)) {
            throw new InvalidArgumentException('A resolved CAP must correspond exactly to a resolved address status.');
        }
        if ($status === SourceCapVerificationStatus::MATCH
            && ($sourceCapForComparison === null || $resolvedCap === null || $sourceCapForComparison !== $resolvedCap || $suggestedCorrection !== null)) {
            throw new InvalidArgumentException('A source CAP match requires equal valid source and resolved CAPs.');
        }
        if ($status === SourceCapVerificationStatus::MISMATCH
            && ($sourceCapForComparison === null || $resolvedCap === null || $sourceCapForComparison === $resolvedCap || $suggestedCorrection === null)) {
            throw new InvalidArgumentException('A source CAP mismatch requires different valid source and resolved CAPs.');
        }
        if ($status === SourceCapVerificationStatus::UNVERIFIABLE
            && ($sourceCapForComparison === null || $resolutionStatus === AddressResolutionStatus::RESOLVED || $suggestedCorrection !== null)) {
            throw new InvalidArgumentException('An unverifiable result requires a valid source CAP and an unresolved address.');
        }
        if (in_array($status, [SourceCapVerificationStatus::SOURCE_MISSING, SourceCapVerificationStatus::SOURCE_INVALID], true)
            && $sourceCapForComparison !== null) {
            throw new InvalidArgumentException('Missing or invalid source CAPs cannot be used for comparison.');
        }
        if ($status === SourceCapVerificationStatus::SOURCE_MISSING && trim($sourceCapOriginal, " \t\n\r\f\v") !== '') {
            throw new InvalidArgumentException('A missing source CAP must contain only surrounding whitespace.');
        }
        if ($status === SourceCapVerificationStatus::SOURCE_INVALID
            && (trim($sourceCapOriginal, " \t\n\r\f\v") === ''
                || preg_match('/\A[0-9]{5}\z/', trim($sourceCapOriginal, " \t\n\r\f\v")) === 1)) {
            throw new InvalidArgumentException('An invalid source CAP must be non-empty and not contain exactly five ASCII digits.');
        }
        if ($suggestedCorrection !== null && $resolvedCap === null) {
            throw new InvalidArgumentException('A correction can only be suggested for a uniquely resolved CAP.');
        }
        if ($suggestedCorrection !== null
            && ($suggestedCorrection->sourceCapOriginal !== $sourceCapOriginal
                || $suggestedCorrection->proposedCap !== $resolvedCap
                || !in_array($status, [SourceCapVerificationStatus::SOURCE_MISSING, SourceCapVerificationStatus::SOURCE_INVALID, SourceCapVerificationStatus::MISMATCH], true))) {
            throw new InvalidArgumentException('A suggested correction must match the original CAP, resolved CAP, and verification status.');
        }
        if ($suggestedCorrection !== null) {
            $expectedReason = match ($status) {
                SourceCapVerificationStatus::SOURCE_MISSING => SourceCapCorrectionReason::SOURCE_CAP_MISSING,
                SourceCapVerificationStatus::SOURCE_INVALID => SourceCapCorrectionReason::SOURCE_CAP_INVALID,
                SourceCapVerificationStatus::MISMATCH => SourceCapCorrectionReason::SOURCE_CAP_MISMATCH,
                default => null,
            };
            if ($suggestedCorrection->reason !== $expectedReason) {
                throw new InvalidArgumentException('Suggested correction reason must correspond to the verification status.');
            }
        }
    }
}
