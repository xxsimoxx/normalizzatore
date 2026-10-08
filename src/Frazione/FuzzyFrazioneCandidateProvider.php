<?php

declare(strict_types=1);

namespace Normalizzatore\Frazione;

interface FuzzyFrazioneCandidateProvider
{
    /** @return list<FrazioneEntry> Entries for candidate names found by local one-edit lookup. */
    public function findFuzzyCandidates(string $sourceName, FuzzyFrazioneMatchingOptions $options): array;
}
