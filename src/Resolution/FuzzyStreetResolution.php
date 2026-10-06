<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use InvalidArgumentException;

/** Result of nominal fuzzy matching only; it does not resolve an address or CAP. */
final readonly class FuzzyStreetResolution
{
    /**
     * @param list<FuzzyStreetMatchEvidence> $ambiguousCandidates
     * @param list<FuzzyStreetDiagnostic> $diagnostics
     */
    public function __construct(
        public FuzzyStreetResolutionStatus $status,
        public ?FuzzyStreetMatchEvidence $match = null,
        public array $ambiguousCandidates = [],
        public array $diagnostics = [],
    ) {
        if (!array_is_list($ambiguousCandidates) || !array_is_list($diagnostics)) {
            throw new InvalidArgumentException('Fuzzy street result collections must be lists.');
        }
        foreach ($ambiguousCandidates as $candidate) {
            if (!$candidate instanceof FuzzyStreetMatchEvidence) {
                throw new InvalidArgumentException('Ambiguous fuzzy candidates must contain typed match evidence.');
            }
        }
        foreach ($diagnostics as $diagnostic) {
            if (!$diagnostic instanceof FuzzyStreetDiagnostic) {
                throw new InvalidArgumentException('Fuzzy diagnostics must use FuzzyStreetDiagnostic values.');
            }
        }
        $candidateNames = array_map(static fn (FuzzyStreetMatchEvidence $item): string => $item->candidate->canonicalName, $ambiguousCandidates);
        $sortedCandidateNames = $candidateNames;
        sort($sortedCandidateNames, SORT_STRING);
        if ($candidateNames !== $sortedCandidateNames || count(array_unique($candidateNames)) !== count($candidateNames)) {
            throw new InvalidArgumentException('Ambiguous fuzzy candidates must be unique and canonically sorted.');
        }
        if ($ambiguousCandidates !== []) {
            $first = $ambiguousCandidates[0];
            foreach ($ambiguousCandidates as $candidate) {
                if ($candidate->source->canonicalName !== $first->source->canonicalName || $candidate->kind !== $first->kind) {
                    throw new InvalidArgumentException('Ambiguous fuzzy evidence must describe the same source and match strategy.');
                }
            }
        }

        if ($status === FuzzyStreetResolutionStatus::MATCH
            && ($match === null || $ambiguousCandidates !== [] || $diagnostics !== [])) {
            throw new InvalidArgumentException('A fuzzy match requires one selected evidence and no ambiguity evidence.');
        }
        if ($status === FuzzyStreetResolutionStatus::AMBIGUOUS
            && ($match !== null || count($ambiguousCandidates) < 2 || $diagnostics === [])) {
            throw new InvalidArgumentException('An ambiguous fuzzy result requires multiple candidates and a diagnostic.');
        }
        if (in_array($status, [FuzzyStreetResolutionStatus::NO_MATCH, FuzzyStreetResolutionStatus::NOT_APPLICABLE], true)
            && ($match !== null || $ambiguousCandidates !== [] || $diagnostics !== [])) {
            throw new InvalidArgumentException('A fuzzy non-match cannot carry selected or ambiguous candidate evidence.');
        }
        if (count(array_unique(array_map(static fn (FuzzyStreetDiagnostic $item): string => $item->value, $diagnostics))) !== count($diagnostics)) {
            throw new InvalidArgumentException('Fuzzy diagnostics must be unique.');
        }
    }

    public static function match(FuzzyStreetMatchEvidence $evidence): self
    {
        return new self(FuzzyStreetResolutionStatus::MATCH, $evidence);
    }

    /** @param list<FuzzyStreetMatchEvidence> $candidates @param list<FuzzyStreetDiagnostic> $diagnostics */
    public static function ambiguous(array $candidates, array $diagnostics): self
    {
        return new self(FuzzyStreetResolutionStatus::AMBIGUOUS, null, $candidates, $diagnostics);
    }

    public static function noMatch(): self
    {
        return new self(FuzzyStreetResolutionStatus::NO_MATCH);
    }

    public static function notApplicable(): self
    {
        return new self(FuzzyStreetResolutionStatus::NOT_APPLICABLE);
    }
}
