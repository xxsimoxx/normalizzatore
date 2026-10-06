<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use InvalidArgumentException;
use Normalizzatore\Address\StreetTokenPolicy;
use Normalizzatore\Address\TokenizedStreetName;

/** Matches a single non-functional nominal token typo within a fixed street structure. */
final readonly class TypoStreetMatcher
{
    public function __construct(
        private FuzzyStreetMatchingOptions $options = new FuzzyStreetMatchingOptions(),
        private OptimalStringAlignmentDistance $distance = new OptimalStringAlignmentDistance(),
        private StreetTokenPolicy $tokenPolicy = new StreetTokenPolicy(),
    ) {
    }

    /** @param list<FuzzyStreetNameCandidate> $candidates */
    public function match(TokenizedStreetName $source, array $candidates): FuzzyStreetResolution
    {
        $this->assertCandidates($candidates);
        if ($this->hasExactCandidate($source, $candidates)) {
            return FuzzyStreetResolution::notApplicable();
        }

        if (!$this->hasTypoEligibleSourceToken($source)) {
            return FuzzyStreetResolution::notApplicable();
        }
        if ($candidates === []) {
            return FuzzyStreetResolution::noMatch();
        }

        /** @var array<string, array{street: TokenizedStreetName, position: int, sourceToken: string, candidateToken: string, distance: int, normalizedDistance: float}> $scored */
        $scored = [];
        foreach ($candidates as $candidate) {
            $street = $candidate->streetName;
            if ($street->streetType !== $source->streetType || $street->tokenCount() !== $source->tokenCount()) {
                continue;
            }

            $differentPosition = null;
            foreach ($source->nominalTokens as $position => $sourceToken) {
                $candidateToken = $street->nominalTokens[$position];
                if ($sourceToken === $candidateToken) {
                    continue;
                }
                if ($differentPosition !== null
                    || $this->tokenPolicy->isFunctional($sourceToken)
                    || $this->tokenPolicy->isFunctional($candidateToken)
                    || mb_strlen($sourceToken, 'UTF-8') < $this->options->minimumTypoTokenLength
                    || mb_strlen($candidateToken, 'UTF-8') < $this->options->minimumTypoTokenLength) {
                    $differentPosition = null;
                    continue 2;
                }
                $differentPosition = $position;
            }
            if ($differentPosition === null) {
                continue;
            }

            $sourceToken = $source->nominalTokens[$differentPosition];
            $candidateToken = $street->nominalTokens[$differentPosition];
            $distance = $this->distance->distance($sourceToken, $candidateToken);
            $normalizer = max(
                mb_strlen($sourceToken, 'UTF-8'),
                mb_strlen($candidateToken, 'UTF-8'),
            );
            $scored[$street->canonicalName] = [
                'street' => $street,
                'position' => $differentPosition,
                'sourceToken' => $sourceToken,
                'candidateToken' => $candidateToken,
                'distance' => $distance,
                'normalizedDistance' => $normalizer === 0 ? 0.0 : $distance / $normalizer,
            ];
        }

        if ($scored === []) {
            return FuzzyStreetResolution::notApplicable();
        }

        uasort($scored, static function (array $left, array $right): int {
            return ($left['distance'] <=> $right['distance'])
                ?: strcmp($left['street']->canonicalName, $right['street']->canonicalName);
        });
        $ranked = array_values($scored);
        $best = $ranked[0];
        if ($best['distance'] > $this->options->maximumTypoDistance) {
            return FuzzyStreetResolution::noMatch();
        }

        $second = $ranked[1] ?? null;
        $secondDistance = $second['distance'] ?? null;
        $margin = $secondDistance === null ? null : $secondDistance - $best['distance'];
        $bestEvidence = $this->evidence($source, $best, $secondDistance, $margin);

        if ($second !== null && $margin === 0) {
            $tied = array_values(array_filter(
                $ranked,
                static fn (array $item): bool => $item['distance'] === $best['distance'],
            ));
            $tiedEvidence = array_map(fn (array $item): FuzzyStreetMatchEvidence => $this->evidence($source, $item), $tied);
            $tiedEvidence[0] = $this->evidence($source, $best, $secondDistance, $margin);

            return FuzzyStreetResolution::ambiguous(
                $tiedEvidence,
                [FuzzyStreetDiagnostic::BEST_DISTANCE_TIE],
            );
        }

        if ($second !== null && $margin < $this->options->minimumDistanceMargin) {
            $ambiguousEvidence = [$bestEvidence, $this->evidence($source, $second)];
            usort($ambiguousEvidence, static fn (FuzzyStreetMatchEvidence $left, FuzzyStreetMatchEvidence $right): int => strcmp(
                $left->candidate->canonicalName,
                $right->candidate->canonicalName,
            ));

            return FuzzyStreetResolution::ambiguous(
                $ambiguousEvidence,
                [FuzzyStreetDiagnostic::DISTANCE_MARGIN_TOO_SMALL],
            );
        }

        return FuzzyStreetResolution::match($bestEvidence);
    }

    private function hasTypoEligibleSourceToken(TokenizedStreetName $source): bool
    {
        foreach ($source->nominalTokens as $token) {
            if (!$this->tokenPolicy->isFunctional($token)
                && mb_strlen($token, 'UTF-8') >= $this->options->minimumTypoTokenLength) {
                return true;
            }
        }

        return false;
    }

    /** @param array{street: TokenizedStreetName, position: int, sourceToken: string, candidateToken: string, distance: int, normalizedDistance: float} $item */
    private function evidence(
        TokenizedStreetName $source,
        array $item,
        ?int $secondBestDistance = null,
        ?int $margin = null,
    ): FuzzyStreetMatchEvidence {
        return new FuzzyStreetMatchEvidence(
            $source,
            $item['street'],
            FuzzyStreetMatchKind::TYPO,
            $item['position'],
            $item['sourceToken'],
            $item['candidateToken'],
            $item['distance'],
            $item['normalizedDistance'],
            $secondBestDistance,
            $margin,
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
