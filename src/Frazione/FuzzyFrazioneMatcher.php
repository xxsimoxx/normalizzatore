<?php

declare(strict_types=1);

namespace Normalizzatore\Frazione;

use InvalidArgumentException;
use Normalizzatore\Directory\DirectoryKeyNormalizer;
use Normalizzatore\Resolution\OptimalStringAlignmentDistance;

/** Applies the one-token, one-edit fraction-name grammar to catalog candidates. */
final readonly class FuzzyFrazioneMatcher
{
    public function __construct(
        private FuzzyFrazioneMatchingOptions $options = new FuzzyFrazioneMatchingOptions(),
        private DirectoryKeyNormalizer $keyNormalizer = new DirectoryKeyNormalizer(),
        private OptimalStringAlignmentDistance $distance = new OptimalStringAlignmentDistance(),
    ) {
    }

    /** @param list<FrazioneEntry> $entries */
    public function match(string $sourceName, array $entries): FuzzyFrazioneMatch
    {
        $sourceKey = $this->keyNormalizer->normalize($sourceName);
        $sourceTokens = $this->tokens($sourceKey);
        if ($sourceKey === '' || !$this->hasEligibleToken($sourceTokens)) {
            return new FuzzyFrazioneMatch(FuzzyFrazioneMatchStatus::NOT_APPLICABLE);
        }

        $groups = [];
        foreach ($entries as $entry) {
            if (!$entry instanceof FrazioneEntry) {
                throw new InvalidArgumentException('Fuzzy fraction input must contain FrazioneEntry values.');
            }
            $candidateKey = $this->keyNormalizer->normalize($entry->frazione);
            if ($candidateKey === '' || $candidateKey === $sourceKey) {
                continue;
            }
            $candidateTokens = $this->tokens($candidateKey);
            if (count($candidateTokens) !== count($sourceTokens)) {
                continue;
            }
            $different = [];
            foreach ($sourceTokens as $position => $token) {
                if ($token !== $candidateTokens[$position]) {
                    $different[] = $position;
                }
            }
            if (count($different) !== 1) {
                continue;
            }
            $position = $different[0];
            $sourceToken = $sourceTokens[$position];
            $candidateToken = $candidateTokens[$position];
            if (!$this->isEligibleToken($sourceToken)
                || !$this->isEligibleToken($candidateToken)
                || $this->distance->distance($sourceToken, $candidateToken) !== 1
                || $this->distance->distance($sourceKey, $candidateKey) !== 1) {
                continue;
            }
            $groups[$candidateKey][] = $entry;
        }

        if ($groups === []) {
            return new FuzzyFrazioneMatch(FuzzyFrazioneMatchStatus::NO_MATCH);
        }
        ksort($groups, SORT_STRING);
        $candidates = [];
        foreach ($groups as $key => $candidateEntries) {
            usort($candidateEntries, static fn (FrazioneEntry $a, FrazioneEntry $b): int =>
                [$a->frazione, $a->comune, $a->provincia, $a->tipo, $a->cap, $a->lineNumber]
                <=> [$b->frazione, $b->comune, $b->provincia, $b->tipo, $b->cap, $b->lineNumber]);
            $sourceTokens = $this->tokens($sourceKey);
            $candidateTokens = $this->tokens($key);
            $position = 0;
            while (($sourceTokens[$position] ?? null) === ($candidateTokens[$position] ?? null)) {
                ++$position;
            }
            $operation = $this->operation($sourceTokens[$position], $candidateTokens[$position]);
            $candidates[] = new FuzzyFrazioneCandidate($key, $candidateEntries, 1, $operation);
        }

        if (count($candidates) > 1) {
            return new FuzzyFrazioneMatch(FuzzyFrazioneMatchStatus::AMBIGUOUS, $candidates);
        }

        return new FuzzyFrazioneMatch(FuzzyFrazioneMatchStatus::MATCH, $candidates, $candidates[0]);
    }

    public function options(): FuzzyFrazioneMatchingOptions
    {
        return $this->options;
    }

    /** @param list<string> $tokens */
    private function hasEligibleToken(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if ($this->isEligibleToken($token)) {
                return true;
            }
        }
        return false;
    }

    private function isEligibleToken(string $token): bool
    {
        return mb_strlen($token, 'UTF-8') >= $this->options->minimumTokenLength
            && preg_match('/\A\p{L}+\z/u', $token) === 1;
    }

    /** @return list<string> */
    private function tokens(string $key): array
    {
        return $key === '' ? [] : explode(' ', $key);
    }

    private function operation(string $source, string $candidate): FuzzyFrazioneEditOperation
    {
        $left = mb_str_split($source, 1, 'UTF-8');
        $right = mb_str_split($candidate, 1, 'UTF-8');
        if (count($right) > count($left)) {
            return FuzzyFrazioneEditOperation::INSERTION;
        }
        if (count($right) < count($left)) {
            return FuzzyFrazioneEditOperation::DELETION;
        }
        for ($i = 0; $i + 1 < count($left); ++$i) {
            $swapped = $left;
            [$swapped[$i], $swapped[$i + 1]] = [$swapped[$i + 1], $swapped[$i]];
            if ($swapped === $right) {
                return FuzzyFrazioneEditOperation::TRANSPOSITION;
            }
        }

        return FuzzyFrazioneEditOperation::SUBSTITUTION;
    }
}
