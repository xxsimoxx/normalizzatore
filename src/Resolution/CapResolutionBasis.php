<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

enum CapResolutionBasis: string
{
    case CIVIC_RANGE = 'civic_range';
    case UNIQUE_TERRITORIAL_CAP = 'unique_territorial_cap';
    case NONE = 'none';
}
