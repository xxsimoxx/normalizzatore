<?php

declare(strict_types=1);

namespace Normalizzatore\Frazione;

use InvalidArgumentException;

/** Typed nominal/territorial evidence for resolving an input city as a frazione. */
final readonly class FrazioneResolution
{
    /**
     * @param list<FrazioneEntry> $entries
     * @param list<array{comune:string,provincia:string}> $candidateMunicipalities
     * @param list<FrazioneEntry> $incompleteAlternatives
     */
    public function __construct(
        public FrazioneResolutionStatus $status,
        public string $sourceName,
        public array $entries = [],
        public array $candidateMunicipalities = [],
        public ?string $comune = null,
        public ?string $provincia = null,
        public FrazioneTypeGroup $typeGroup = FrazioneTypeGroup::UNKNOWN,
        public ?FrazioneResolutionDiagnostic $diagnostic = null,
        public bool $catalogCapConflict = false,
        public ?FrazioneStreetEvidence $streetEvidence = null,
        public array $incompleteAlternatives = [],
    ) {
        if (!array_is_list($entries) || !array_is_list($candidateMunicipalities) || !array_is_list($incompleteAlternatives)) {
            throw new InvalidArgumentException('Frazione evidence collections must be lists.');
        }
        foreach ($entries as $entry) {
            if (!$entry instanceof FrazioneEntry) {
                throw new InvalidArgumentException('Frazione evidence must contain FrazioneEntry values.');
            }
        }
        foreach ($incompleteAlternatives as $entry) {
            if (!$entry instanceof FrazioneEntry) {
                throw new InvalidArgumentException('Incomplete frazione alternatives must contain FrazioneEntry values.');
            }
        }
        if (($status === FrazioneResolutionStatus::MATCH) !== ($comune !== null && $provincia !== null)) {
            throw new InvalidArgumentException('Only a frazione MATCH may select a verified comune/provincia pair.');
        }
        if ($status === FrazioneResolutionStatus::MATCH && count($candidateMunicipalities) !== 1) {
            throw new InvalidArgumentException('A frazione MATCH requires exactly one municipality candidate.');
        }
    }
}
