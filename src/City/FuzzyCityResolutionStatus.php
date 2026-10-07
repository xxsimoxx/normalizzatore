<?php

declare(strict_types=1);

namespace Normalizzatore\City;

enum FuzzyCityResolutionStatus: string
{
    case MATCH = 'MATCH';
    case AMBIGUOUS = 'AMBIGUOUS';
    case NO_MATCH = 'NO_MATCH';
    case NOT_APPLICABLE = 'NOT_APPLICABLE';
}
