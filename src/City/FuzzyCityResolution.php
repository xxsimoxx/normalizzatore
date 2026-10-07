<?php

declare(strict_types=1);

namespace Normalizzatore\City;

use InvalidArgumentException;

/** Typed nominal outcome of conservative city matching. */
final readonly class FuzzyCityResolution
{
    /**
     * @param list<CityCandidate> $ambiguousCandidates
     */
    public function __construct(
        public FuzzyCityResolutionStatus $status,
        public ?FuzzyCityMatchEvidence $match = null,
        public array $ambiguousCandidates = [],
    ) {
        if (!array_is_list($ambiguousCandidates)) {
            throw new InvalidArgumentException('Ambiguous city candidates must be a list.');
        }
        foreach ($ambiguousCandidates as $candidate) {
            if (!$candidate instanceof CityCandidate) {
                throw new InvalidArgumentException('Ambiguous city candidates must contain CityCandidate values.');
            }
        }
        if (($status === FuzzyCityResolutionStatus::MATCH) !== ($match !== null)
            || ($status === FuzzyCityResolutionStatus::AMBIGUOUS) !== (count($ambiguousCandidates) >= 2)
            || ($status !== FuzzyCityResolutionStatus::AMBIGUOUS && $ambiguousCandidates !== [])) {
            throw new InvalidArgumentException('Fuzzy city resolution evidence does not agree with its status.');
        }
    }
}
