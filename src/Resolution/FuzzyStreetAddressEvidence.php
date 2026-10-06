<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use InvalidArgumentException;
use Normalizzatore\Address\AddressCandidate;
use Normalizzatore\Directory\FuzzyStreetCandidateSet;
use Normalizzatore\Directory\FuzzyStreetCandidateSetStatus;

/** Typed record of an optional fuzzy attempt, separate from the resulting CAP resolution. */
final readonly class FuzzyStreetAddressEvidence
{
    public function __construct(
        public AddressResolutionDiagnostic $diagnostic,
        public ?AddressCandidate $parserCandidate = null,
        public ?FuzzyStreetCandidateSet $candidateSet = null,
        public ?FuzzyStreetResolution $nominalResolution = null,
    ) {
        if (!str_starts_with($diagnostic->value, 'fuzzy_')) {
            throw new InvalidArgumentException('Fuzzy address evidence requires a fuzzy resolution diagnostic.');
        }
        if ($nominalResolution?->status === FuzzyStreetResolutionStatus::MATCH
            && ($parserCandidate === null || $candidateSet?->status !== FuzzyStreetCandidateSetStatus::AVAILABLE)) {
            throw new InvalidArgumentException('A fuzzy nominal match requires its parser candidate and applicable provider result.');
        }
        if ($diagnostic === AddressResolutionDiagnostic::FUZZY_ABBREVIATION_MATCH
            && $nominalResolution?->match?->kind !== FuzzyStreetMatchKind::ABBREVIATION) {
            throw new InvalidArgumentException('Abbreviation diagnostics require abbreviation match evidence.');
        }
        if ($diagnostic === AddressResolutionDiagnostic::FUZZY_TYPO_MATCH
            && $nominalResolution?->match?->kind !== FuzzyStreetMatchKind::TYPO) {
            throw new InvalidArgumentException('Typo diagnostics require typo match evidence.');
        }
        if ($diagnostic === AddressResolutionDiagnostic::FUZZY_AMBIGUOUS
            && $nominalResolution?->status !== FuzzyStreetResolutionStatus::AMBIGUOUS) {
            throw new InvalidArgumentException('Fuzzy ambiguity diagnostics require ambiguous nominal evidence.');
        }
        if ($diagnostic === AddressResolutionDiagnostic::FUZZY_PROVINCE_CONFLICT
            && $candidateSet?->status !== FuzzyStreetCandidateSetStatus::PROVINCE_CONFLICT) {
            throw new InvalidArgumentException('Province-conflict diagnostics require a conflicting provider result.');
        }
        if ($diagnostic === AddressResolutionDiagnostic::FUZZY_AMBIGUOUS_LOCALITY
            && $candidateSet?->status !== FuzzyStreetCandidateSetStatus::AMBIGUOUS_LOCALITY) {
            throw new InvalidArgumentException('Locality-ambiguity diagnostics require an ambiguous provider result.');
        }
        if ($diagnostic === AddressResolutionDiagnostic::FUZZY_NO_LOCALITY
            && $candidateSet?->status !== FuzzyStreetCandidateSetStatus::NO_LOCALITY) {
            throw new InvalidArgumentException('No-locality diagnostics require an empty provider result.');
        }
        if ($diagnostic === AddressResolutionDiagnostic::FUZZY_NO_MATCH
            && $nominalResolution?->status !== FuzzyStreetResolutionStatus::NO_MATCH) {
            throw new InvalidArgumentException('Fuzzy no-match diagnostics require a nominal no-match result.');
        }
    }
}
