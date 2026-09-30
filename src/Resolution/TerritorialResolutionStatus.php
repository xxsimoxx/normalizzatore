<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

enum TerritorialResolutionStatus: string
{
    case RESOLVED = 'resolved';
    case NO_MATCH = 'no_match';
    case AMBIGUOUS = 'ambiguous';
    case INDETERMINATE = 'indeterminate';
}
