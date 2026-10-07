<?php

declare(strict_types=1);

namespace Normalizzatore\Directory;

use Normalizzatore\City\CityCandidate;

/** Supplies logical city/province names for the pure fuzzy city resolver. */
interface FuzzyCityCandidateProvider
{
    /** @return list<CityCandidate> */
    public function findCityCandidates(): array;
}
