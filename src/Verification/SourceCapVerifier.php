<?php

declare(strict_types=1);

namespace Normalizzatore\Verification;

use Normalizzatore\Resolution\AddressResolution;
use Normalizzatore\Resolution\AddressResolutionStatus;

/** Purely compares a source CAP with an already computed address resolution. */
final class SourceCapVerifier
{
    public function verify(string $sourceCap, AddressResolution $resolution): SourceCapVerification
    {
        // Trim surrounding ASCII whitespace only. PHP's default trim also strips
        // NUL bytes, which are not whitespace and must not make an invalid CAP valid.
        $trimmedSourceCap = trim($sourceCap, " \t\n\r\f\v");
        $isMissing = $trimmedSourceCap === '';
        $isValid = !$isMissing && preg_match('/\A[0-9]{5}\z/', $trimmedSourceCap) === 1;
        $comparisonCap = $isValid ? $trimmedSourceCap : null;
        $sourceIsCandidate = $comparisonCap === null
            ? null
            : in_array($comparisonCap, $resolution->candidateCaps, true);

        $diagnostics = [];
        if ($isMissing) {
            $diagnostics[] = SourceCapVerificationDiagnostic::SOURCE_CAP_MISSING;
            $status = SourceCapVerificationStatus::SOURCE_MISSING;
            $reason = SourceCapCorrectionReason::SOURCE_CAP_MISSING;
        } elseif (!$isValid) {
            $diagnostics[] = SourceCapVerificationDiagnostic::SOURCE_CAP_INVALID;
            $status = SourceCapVerificationStatus::SOURCE_INVALID;
            $reason = SourceCapCorrectionReason::SOURCE_CAP_INVALID;
        } elseif ($resolution->status !== AddressResolutionStatus::RESOLVED) {
            $status = SourceCapVerificationStatus::UNVERIFIABLE;
            $reason = null;
            $diagnostics[] = SourceCapVerificationDiagnostic::RESOLUTION_UNVERIFIABLE;
            $diagnostics[] = $sourceIsCandidate
                ? SourceCapVerificationDiagnostic::SOURCE_CAP_PRESENT_AMONG_CANDIDATES
                : SourceCapVerificationDiagnostic::SOURCE_CAP_ABSENT_FROM_CANDIDATES;
        } elseif ($comparisonCap === $resolution->resolvedCap) {
            $status = SourceCapVerificationStatus::MATCH;
            $reason = null;
        } else {
            $status = SourceCapVerificationStatus::MISMATCH;
            $reason = SourceCapCorrectionReason::SOURCE_CAP_MISMATCH;
            $diagnostics[] = SourceCapVerificationDiagnostic::SOURCE_CAP_DIFFERS;
        }

        $correction = $resolution->resolvedCap !== null && $reason !== null
            ? new SourceCapCorrection($sourceCap, $resolution->resolvedCap, $reason)
            : null;

        return new SourceCapVerification(
            $sourceCap,
            $comparisonCap,
            $status,
            $resolution->status,
            $resolution->resolvedCap,
            $resolution->candidateCaps,
            $sourceIsCandidate,
            $correction,
            $diagnostics,
        );
    }
}
