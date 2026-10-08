<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use InvalidArgumentException;
use Normalizzatore\Address\AddressResolutionStrategy;
use Normalizzatore\City\FuzzyCityResolution;
use Normalizzatore\Frazione\FrazioneResolution;

/** Common, typed result of either address-resolution path. */
final readonly class AddressResolution
{
    /**
     * @param list<string> $candidateCaps
     * @param list<StreetCandidateResolution> $streetCandidateResolutions
     * @param list<AddressResolutionDiagnostic> $diagnostics
     */
    public function __construct(
        public AddressResolutionStrategy $strategy,
        public AddressResolutionStatus $status,
        public array $candidateCaps,
        public ?string $resolvedCap,
        public ?TerritorialResolution $territorialResolution,
        public array $streetCandidateResolutions,
        public array $diagnostics,
        public ?FuzzyStreetAddressEvidence $fuzzyStreetEvidence = null,
        public ?FuzzyCityResolution $fuzzyCityResolution = null,
        public ?AddressGeographicEvidence $geographicEvidence = null,
        public ?FrazioneResolution $frazioneResolution = null,
    ) {
        foreach ([$candidateCaps, $streetCandidateResolutions, $diagnostics] as $list) {
            if (!array_is_list($list)) {
                throw new InvalidArgumentException('Address resolution collections must be lists.');
            }
        }

        $sortedCaps = $candidateCaps;
        sort($sortedCaps, SORT_STRING);
        if ($candidateCaps !== $sortedCaps || count(array_unique($candidateCaps)) !== count($candidateCaps)) {
            throw new InvalidArgumentException('Address candidate CAPs must be unique and sorted lexicographically.');
        }
        foreach ($candidateCaps as $cap) {
            if (preg_match('/\A[0-9]{5}\z/', $cap) !== 1) {
                throw new InvalidArgumentException('Address candidate CAPs must contain exactly five ASCII digits.');
            }
        }
        foreach ($streetCandidateResolutions as $candidateResolution) {
            if (!$candidateResolution instanceof StreetCandidateResolution) {
                throw new InvalidArgumentException('Street resolution evidence must contain StreetCandidateResolution values.');
            }
        }
        foreach ($diagnostics as $diagnostic) {
            if (!$diagnostic instanceof AddressResolutionDiagnostic) {
                throw new InvalidArgumentException('Address resolution diagnostics must use AddressResolutionDiagnostic values.');
            }
        }
        if (count(array_unique(array_map(static fn (AddressResolutionDiagnostic $item): string => $item->value, $diagnostics))) !== count($diagnostics)) {
            throw new InvalidArgumentException('Address resolution diagnostics must be unique.');
        }
        if ($fuzzyStreetEvidence !== null
            && ($strategy !== AddressResolutionStrategy::STREET_BASED
                || !in_array($fuzzyStreetEvidence->diagnostic, $diagnostics, true))) {
            throw new InvalidArgumentException('Fuzzy street evidence must belong to a street-based result and be exposed as a diagnostic.');
        }
        if ($fuzzyCityResolution !== null
            && !in_array($fuzzyCityResolution->status, [
                \Normalizzatore\City\FuzzyCityResolutionStatus::MATCH,
                \Normalizzatore\City\FuzzyCityResolutionStatus::AMBIGUOUS,
                \Normalizzatore\City\FuzzyCityResolutionStatus::NO_MATCH,
                \Normalizzatore\City\FuzzyCityResolutionStatus::NOT_APPLICABLE,
            ], true)) {
            throw new InvalidArgumentException('Fuzzy city evidence must use a typed city resolution status.');
        }
        if ($geographicEvidence !== null && $fuzzyCityResolution?->status !== null
            && $geographicEvidence->kind === AddressGeographicEvidenceKind::FUZZY_CITY_CORRECTION
            && $fuzzyCityResolution->match !== $geographicEvidence->fuzzyCityMatch) {
            throw new InvalidArgumentException('Applied city correction must reference its fuzzy city match evidence.');
        }

        if ($status === AddressResolutionStatus::RESOLVED
            && (count($candidateCaps) !== 1 || $resolvedCap !== $candidateCaps[0])) {
            throw new InvalidArgumentException('A resolved address must expose exactly one resolved CAP.');
        }
        if ($status !== AddressResolutionStatus::RESOLVED && $resolvedCap !== null) {
            throw new InvalidArgumentException('Only a resolved address may expose a resolved CAP.');
        }
        if ($status === AddressResolutionStatus::AMBIGUOUS && count($candidateCaps) < 2) {
            throw new InvalidArgumentException('An ambiguous address must expose multiple candidate CAPs.');
        }
        if ($status === AddressResolutionStatus::NO_MATCH && $candidateCaps !== []) {
            throw new InvalidArgumentException('A no-match address cannot expose candidate CAPs.');
        }

        if ($strategy === AddressResolutionStrategy::TERRITORIAL
            && ($territorialResolution === null || $streetCandidateResolutions !== [] || $fuzzyStreetEvidence !== null)) {
            throw new InvalidArgumentException('A territorial address result must preserve only territorial resolution evidence.');
        }
        if ($strategy === AddressResolutionStrategy::STREET_BASED && $territorialResolution !== null) {
            throw new InvalidArgumentException('A street-based address result cannot contain territorial resolution evidence.');
        }
    }

    public function isResolved(): bool
    {
        return $this->status === AddressResolutionStatus::RESOLVED;
    }
}
