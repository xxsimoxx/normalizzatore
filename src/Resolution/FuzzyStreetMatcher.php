<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use InvalidArgumentException;
use Normalizzatore\Address\TokenizedStreetName;

/** Pure coordinator: abbreviation evidence is evaluated first and ambiguity stops the chain. */
final readonly class FuzzyStreetMatcher
{
    public function __construct(
        private AbbreviationStreetMatcher $abbreviationMatcher = new AbbreviationStreetMatcher(),
        private TypoStreetMatcher $typoMatcher = new TypoStreetMatcher(),
    ) {
    }

    /** @param list<FuzzyStreetNameCandidate> $candidates */
    public function match(TokenizedStreetName $source, array $candidates): FuzzyStreetResolution
    {
        if (!array_is_list($candidates)) {
            throw new InvalidArgumentException('Fuzzy street candidates must be provided as a list.');
        }
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof FuzzyStreetNameCandidate) {
                throw new InvalidArgumentException('Fuzzy candidates must contain FuzzyStreetNameCandidate values.');
            }
            if ($candidate->streetName->canonicalName === $source->canonicalName) {
                return FuzzyStreetResolution::notApplicable();
            }
        }

        $abbreviation = $this->abbreviationMatcher->match($source, $candidates);
        if (in_array($abbreviation->status, [FuzzyStreetResolutionStatus::MATCH, FuzzyStreetResolutionStatus::AMBIGUOUS], true)) {
            return $abbreviation;
        }

        $typo = $this->typoMatcher->match($source, $candidates);
        if ($typo->status !== FuzzyStreetResolutionStatus::NOT_APPLICABLE) {
            return $typo;
        }

        return $abbreviation->status === FuzzyStreetResolutionStatus::NO_MATCH
            ? $abbreviation
            : $typo;
    }
}
