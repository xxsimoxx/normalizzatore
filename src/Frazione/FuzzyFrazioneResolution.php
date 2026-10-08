<?php

declare(strict_types=1);

namespace Normalizzatore\Frazione;

use InvalidArgumentException;

/** End-to-end fuzzy fraction evidence; nominal matching is kept separate from application. */
final readonly class FuzzyFrazioneResolution
{
    /** @param list<FuzzyFrazioneCandidate> $candidates */
    public function __construct(
        public FuzzyFrazioneResolutionStatus $status,
        public string $sourceName,
        public ?string $sourceProvince = null,
        public array $candidates = [],
        public ?FuzzyFrazioneCandidate $selectedCandidate = null,
        public ?FrazioneResolution $territorialResolution = null,
        public ?FrazioneStreetEvidence $streetEvidence = null,
        public ?FuzzyFrazioneResolutionDiagnostic $diagnostic = null,
    ) {
        if (!array_is_list($candidates)) {
            throw new InvalidArgumentException('Fuzzy fraction resolution candidates must be a list.');
        }
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof FuzzyFrazioneCandidate) {
                throw new InvalidArgumentException('Fuzzy fraction resolution candidates must be typed values.');
            }
        }
        if ($selectedCandidate !== null && !in_array($selectedCandidate, $candidates, true)) {
            throw new InvalidArgumentException('Selected fuzzy fraction candidate must be retained in evidence.');
        }
        if ($status === FuzzyFrazioneResolutionStatus::APPLIED
            && ($selectedCandidate === null || $streetEvidence === null || $territorialResolution?->status !== FrazioneResolutionStatus::MATCH)) {
            throw new InvalidArgumentException('Applied fuzzy fraction resolution requires territorial and street evidence.');
        }
        $hasMultipleTerritorialAssociations = $diagnostic === FuzzyFrazioneResolutionDiagnostic::MULTIPLE_TERRITORIAL_ASSOCIATIONS
            && $selectedCandidate !== null
            && count($selectedCandidate->entries) > 1;
        if ($status === FuzzyFrazioneResolutionStatus::AMBIGUOUS
            && count($candidates) < 2
            && !$hasMultipleTerritorialAssociations) {
            throw new InvalidArgumentException('Ambiguous fuzzy fraction resolution requires multiple names or territorial associations.');
        }
    }
}
