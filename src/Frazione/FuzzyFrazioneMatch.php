<?php

declare(strict_types=1);

namespace Normalizzatore\Frazione;

use InvalidArgumentException;

/** Pure nominal result, independent of territory and address corroboration. */
final readonly class FuzzyFrazioneMatch
{
    /** @param list<FuzzyFrazioneCandidate> $candidates */
    public function __construct(
        public FuzzyFrazioneMatchStatus $status,
        public array $candidates = [],
        public ?FuzzyFrazioneCandidate $match = null,
    ) {
        if (!array_is_list($candidates)) {
            throw new InvalidArgumentException('Fuzzy fraction candidates must be a list.');
        }
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof FuzzyFrazioneCandidate) {
                throw new InvalidArgumentException('Fuzzy fraction candidates must use typed values.');
            }
        }
        if (($status === FuzzyFrazioneMatchStatus::MATCH) !== ($match !== null)
            || ($status === FuzzyFrazioneMatchStatus::MATCH && count($candidates) !== 1)
            || ($status === FuzzyFrazioneMatchStatus::AMBIGUOUS && count($candidates) < 2)
            || (in_array($status, [FuzzyFrazioneMatchStatus::NO_MATCH, FuzzyFrazioneMatchStatus::NOT_APPLICABLE], true)
                && ($candidates !== [] || $match !== null))) {
            throw new InvalidArgumentException('Fuzzy fraction match evidence does not agree with its status.');
        }
    }
}
