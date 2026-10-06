<?php

declare(strict_types=1);

namespace Normalizzatore\Directory;

enum FuzzyStreetCandidateSetStatus: string
{
    case AVAILABLE = 'available';
    case NO_LOCALITY = 'no_locality';
    case PROVINCE_CONFLICT = 'province_conflict';
    case AMBIGUOUS_LOCALITY = 'ambiguous_locality';
}
