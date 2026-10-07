<?php

declare(strict_types=1);

namespace Normalizzatore\City;

use Normalizzatore\Directory\DirectoryKeyNormalizer;
use Normalizzatore\Resolution\OptimalStringAlignmentDistance;

/** Pure matcher for single-edit city-name typos. */
final readonly class FuzzyCityResolver
{
    public function __construct(
        private FuzzyCityMatchingOptions $options = new FuzzyCityMatchingOptions(),
        private OptimalStringAlignmentDistance $distance = new OptimalStringAlignmentDistance(),
        private DirectoryKeyNormalizer $keyNormalizer = new DirectoryKeyNormalizer(),
    ) {
    }

    /** @param list<CityCandidate> $candidates */
    public function resolve(string $sourceCity, ?string $sourceProvince, array $candidates): FuzzyCityResolution
    {
        $sourceKey = $this->keyNormalizer->normalize($sourceCity);
        $sourceLength = mb_strlen($sourceKey, 'UTF-8');
        if ($sourceKey === '' || $sourceLength < $this->options->minimumNameLength) {
            return new FuzzyCityResolution(FuzzyCityResolutionStatus::NOT_APPLICABLE);
        }
        $maximumCandidateDistance = $this->options->maximumDistance + $this->options->minimumDistanceMargin;

        $eligible = [];
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof CityCandidate) {
                throw new \InvalidArgumentException('City candidates must contain CityCandidate values.');
            }
            $candidateLength = mb_strlen($candidate->canonicalKey, 'UTF-8');
            if ($candidateLength >= $this->options->minimumNameLength
                && abs($candidateLength - $sourceLength) <= $maximumCandidateDistance
                && $this->mayBeWithinDistance($sourceKey, $candidate->canonicalKey, $maximumCandidateDistance)) {
                $eligible[] = $candidate;
            }
        }
        $provinceKey = trim((string) $sourceProvince);
        $provinceScoped = preg_match('/\A[A-Za-z]{2}\z/', $provinceKey) === 1;
        if ($provinceScoped) {
            $provinceKey = strtoupper($this->keyNormalizer->normalize($provinceKey));
            $scoped = array_values(array_filter(
                $eligible,
                static fn (CityCandidate $candidate): bool => strtoupper($candidate->province) === $provinceKey,
            ));
            if ($scoped !== []) {
                $eligible = $scoped;
            } else {
                $provinceScoped = false;
            }
        }

        /** @var array<string, array{distance: int, candidates: list<CityCandidate>}> $scores */
        $scores = [];
        foreach ($eligible as $candidate) {
            $distance = $this->distance->distance($sourceKey, $candidate->canonicalKey);
            if ($distance === 0) {
                // Exact/canonical city lookup must win before this matcher is called.
                return new FuzzyCityResolution(FuzzyCityResolutionStatus::NOT_APPLICABLE);
            }
            if ($distance > $maximumCandidateDistance) {
                continue;
            }

            $key = $candidate->canonicalKey;
            if (!isset($scores[$key]) || $distance < $scores[$key]['distance']) {
                $scores[$key] = ['distance' => $distance, 'candidates' => [$candidate]];
            } elseif ($distance === $scores[$key]['distance']) {
                $scores[$key]['candidates'][] = $candidate;
            }
        }

        if ($scores === []) {
            return new FuzzyCityResolution(FuzzyCityResolutionStatus::NO_MATCH);
        }

        uasort($scores, static fn (array $left, array $right): int => $left['distance'] <=> $right['distance']);
        $ranked = array_values($scores);
        $best = $ranked[0];
        if ($best['distance'] > $this->options->maximumDistance) {
            return new FuzzyCityResolution(FuzzyCityResolutionStatus::NO_MATCH);
        }

        $secondBestDistance = $ranked[1]['distance'] ?? null;
        $margin = $secondBestDistance === null ? null : $secondBestDistance - $best['distance'];
        $bestCandidates = $best['candidates'];
        if (count($bestCandidates) !== 1 || ($margin !== null && $margin < $this->options->minimumDistanceMargin)) {
            $ambiguous = $bestCandidates;
            if ($margin !== null && $margin < $this->options->minimumDistanceMargin) {
                array_push($ambiguous, ...$ranked[1]['candidates']);
            }
            usort($ambiguous, static fn (CityCandidate $left, CityCandidate $right): int => [$left->canonicalKey, $left->province]
                <=> [$right->canonicalKey, $right->province]);
            $ambiguous = array_values(array_unique($ambiguous, SORT_REGULAR));
            if (count($ambiguous) < 2) {
                return new FuzzyCityResolution(FuzzyCityResolutionStatus::NOT_APPLICABLE);
            }

            return new FuzzyCityResolution(FuzzyCityResolutionStatus::AMBIGUOUS, ambiguousCandidates: $ambiguous);
        }

        return new FuzzyCityResolution(FuzzyCityResolutionStatus::MATCH, new FuzzyCityMatchEvidence(
            $sourceCity,
            $bestCandidates[0],
            $best['distance'],
            $secondBestDistance,
            $margin,
            $provinceScoped,
        ));
    }

    private function mayBeWithinDistance(string $source, string $candidate, int $distance): bool
    {
        $left = mb_str_split($source, 1, 'UTF-8');
        $right = mb_str_split($candidate, 1, 'UTF-8');
        $counts = array_count_values($left);
        $overlap = 0;
        foreach (array_count_values($right) as $character => $count) {
            $overlap += min($count, $counts[$character] ?? 0);
        }

        return $overlap >= min(count($left), count($right)) - $distance;
    }
}
