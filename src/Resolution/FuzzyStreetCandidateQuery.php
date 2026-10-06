<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use Normalizzatore\Address\TokenizedStreetName;

/** Minimal geography and nominal structure needed to retrieve fuzzy street names. */
final readonly class FuzzyStreetCandidateQuery
{
    public function __construct(
        public string $city,
        public string $province,
        public TokenizedStreetName $street,
    ) {
    }
}
