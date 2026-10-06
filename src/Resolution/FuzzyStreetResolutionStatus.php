<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

enum FuzzyStreetResolutionStatus: string
{
    case MATCH = 'match';
    case AMBIGUOUS = 'ambiguous';
    case NO_MATCH = 'no_match';
    case NOT_APPLICABLE = 'not_applicable';
}
