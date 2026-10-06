<?php

declare(strict_types=1);

namespace Normalizzatore\Directory;

use InvalidArgumentException;
use Normalizzatore\Resolution\FuzzyStreetNameCandidate;

/** Candidate retrieval result, keeping diagnostic candidates separate from applicable ones. */
final readonly class FuzzyStreetCandidateSet
{
    /**
     * @param list<FuzzyStreetNameCandidate> $candidates
     * @param list<FuzzyStreetNameCandidate> $diagnosticCandidates
     * @param list<string> $provinceKeys
     */
    public function __construct(
        public FuzzyStreetCandidateSetStatus $status,
        public array $candidates = [],
        public array $diagnosticCandidates = [],
        public ?string $cityKey = null,
        public ?string $provinceKey = null,
        public array $provinceKeys = [],
    ) {
        foreach ([$candidates, $diagnosticCandidates, $provinceKeys] as $list) {
            if (!array_is_list($list)) {
                throw new InvalidArgumentException('Fuzzy candidate-set values must be lists.');
            }
        }
        foreach ([$candidates, $diagnosticCandidates] as $list) {
            foreach ($list as $candidate) {
                if (!$candidate instanceof FuzzyStreetNameCandidate) {
                    throw new InvalidArgumentException('Fuzzy candidate sets must contain typed street candidates.');
                }
            }
            $names = array_map(static fn (FuzzyStreetNameCandidate $item): string => $item->streetName->canonicalName, $list);
            $sortedNames = $names;
            sort($sortedNames, SORT_STRING);
            if ($names !== $sortedNames || count(array_unique($names)) !== count($names)) {
                throw new InvalidArgumentException('Fuzzy candidate names must be unique and sorted canonically.');
            }
        }
        if ($provinceKeys !== array_values(array_unique($provinceKeys))) {
            throw new InvalidArgumentException('Fuzzy locality province keys must be unique.');
        }
        $sortedProvinceKeys = $provinceKeys;
        sort($sortedProvinceKeys, SORT_STRING);
        if ($provinceKeys !== $sortedProvinceKeys) {
            throw new InvalidArgumentException('Fuzzy locality province keys must be sorted.');
        }

        if ($status === FuzzyStreetCandidateSetStatus::AVAILABLE) {
            if ($cityKey === null || $cityKey === '' || $provinceKey === null || $diagnosticCandidates !== []
                || $provinceKeys === [] || !in_array($provinceKey, $provinceKeys, true)) {
                throw new InvalidArgumentException('Available fuzzy candidates require one city/province and no diagnostic-only candidates.');
            }
        } elseif ($status === FuzzyStreetCandidateSetStatus::NO_LOCALITY) {
            if ($candidates !== [] || $diagnosticCandidates !== [] || $cityKey !== null || $provinceKey !== null || $provinceKeys !== []) {
                throw new InvalidArgumentException('An unknown fuzzy locality cannot carry candidates or locality keys.');
            }
        } elseif ($status === FuzzyStreetCandidateSetStatus::PROVINCE_CONFLICT) {
            if ($candidates !== [] || $cityKey === null || $provinceKey !== null || $provinceKeys === []) {
                throw new InvalidArgumentException('Province conflict candidates must remain diagnostic and retain known city provinces.');
            }
        } elseif ($status === FuzzyStreetCandidateSetStatus::AMBIGUOUS_LOCALITY) {
            if ($candidates !== [] || $diagnosticCandidates !== [] || $cityKey === null || $provinceKey !== null || count($provinceKeys) < 2) {
                throw new InvalidArgumentException('Ambiguous fuzzy localities require multiple provinces and no applicable candidates.');
            }
        }
    }
}
