<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use InvalidArgumentException;
use Normalizzatore\Address\StreetTokenPolicy;
use Normalizzatore\Address\TokenizedStreetName;

/** Matches exactly one dotted initial against an otherwise identical street name. */
final readonly class AbbreviationStreetMatcher
{
    public function __construct(private StreetTokenPolicy $tokenPolicy = new StreetTokenPolicy())
    {
    }

    /** @param list<FuzzyStreetNameCandidate> $candidates */
    public function match(TokenizedStreetName $source, array $candidates): FuzzyStreetResolution
    {
        $this->assertCandidates($candidates);
        if ($this->hasExactCandidate($source, $candidates)) {
            return FuzzyStreetResolution::notApplicable();
        }

        $abbreviatedPositions = [];
        foreach ($source->nominalTokens as $position => $token) {
            if (preg_match('/\A\p{L}\.\z/u', $token) === 1) {
                $abbreviatedPositions[] = $position;
            }
        }
        if (count($abbreviatedPositions) !== 1) {
            return FuzzyStreetResolution::notApplicable();
        }

        $position = $abbreviatedPositions[0];
        $initial = mb_substr($source->nominalTokens[$position], 0, 1, 'UTF-8');
        $matches = [];
        foreach ($candidates as $candidate) {
            $street = $candidate->streetName;
            if ($street->streetType !== $source->streetType
                || $street->tokenCount() !== $source->tokenCount()) {
                continue;
            }

            $compatible = true;
            foreach ($source->nominalTokens as $index => $token) {
                if ($index === $position) {
                    continue;
                }
                if ($street->nominalTokens[$index] !== $token) {
                    $compatible = false;
                    break;
                }
            }
            $expandedToken = $street->nominalTokens[$position];
            if (!$compatible
                || $expandedToken === $source->nominalTokens[$position]
                || mb_strlen($expandedToken, 'UTF-8') < 2
                || mb_strtoupper(mb_substr($expandedToken, 0, 1, 'UTF-8'), 'UTF-8') !== $initial
                || $this->tokenPolicy->isFunctional($expandedToken)) {
                continue;
            }

            $matches[$street->canonicalName] = new FuzzyStreetMatchEvidence(
                $source,
                $street,
                FuzzyStreetMatchKind::ABBREVIATION,
                $position,
                $source->nominalTokens[$position],
                $expandedToken,
            );
        }

        ksort($matches, SORT_STRING);
        $evidence = array_values($matches);
        if ($evidence === []) {
            return FuzzyStreetResolution::noMatch();
        }
        if (count($evidence) === 1) {
            return FuzzyStreetResolution::match($evidence[0]);
        }

        return FuzzyStreetResolution::ambiguous(
            $evidence,
            [FuzzyStreetDiagnostic::MULTIPLE_ABBREVIATION_EXPANSIONS],
        );
    }

    /** @param list<FuzzyStreetNameCandidate> $candidates */
    private function hasExactCandidate(TokenizedStreetName $source, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof FuzzyStreetNameCandidate) {
                throw new InvalidArgumentException('Fuzzy candidates must contain FuzzyStreetNameCandidate values.');
            }
            if ($candidate->streetName->canonicalName === $source->canonicalName) {
                return true;
            }
        }

        return false;
    }

    /** @param list<FuzzyStreetNameCandidate> $candidates */
    private function assertCandidates(array $candidates): void
    {
        if (!array_is_list($candidates)) {
            throw new InvalidArgumentException('Fuzzy street candidates must be provided as a list.');
        }
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof FuzzyStreetNameCandidate) {
                throw new InvalidArgumentException('Fuzzy candidates must contain FuzzyStreetNameCandidate values.');
            }
        }
    }
}
