<?php

declare(strict_types=1);

namespace Normalizzatore\Directory;

use Normalizzatore\Resolution\FuzzyStreetCandidateQuery;
use Normalizzatore\Resolution\FuzzyStreetNameCandidate;

/** Retrieves local logical street names for fuzzy matching without making match decisions. */
interface FuzzyStreetCandidateProvider
{
    public function findCandidates(FuzzyStreetCandidateQuery $query): FuzzyStreetCandidateSet;

    /** @return list<DirectoryEntry> */
    public function findEntries(FuzzyStreetCandidateSet $set, FuzzyStreetNameCandidate $candidate): array;
}
