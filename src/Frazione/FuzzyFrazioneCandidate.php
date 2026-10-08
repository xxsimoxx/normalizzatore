<?php

declare(strict_types=1);

namespace Normalizzatore\Frazione;

use InvalidArgumentException;

/** One distinct logical fraction name found at OSA distance one. */
final readonly class FuzzyFrazioneCandidate
{
    /** @param list<FrazioneEntry> $entries */
    public function __construct(
        public string $canonicalName,
        public array $entries,
        public int $distance,
        public FuzzyFrazioneEditOperation $operation,
    ) {
        if ($canonicalName === '' || $entries === [] || $distance !== 1 || !array_is_list($entries)) {
            throw new InvalidArgumentException('Invalid fuzzy fraction candidate.');
        }
        foreach ($entries as $entry) {
            if (!$entry instanceof FrazioneEntry) {
                throw new InvalidArgumentException('Fuzzy fraction candidate entries must be FrazioneEntry values.');
            }
        }
    }
}
