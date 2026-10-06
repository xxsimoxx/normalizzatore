<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

enum FuzzyStreetDiagnostic: string
{
    case MULTIPLE_ABBREVIATION_EXPANSIONS = 'multiple_abbreviation_expansions';
    case BEST_DISTANCE_TIE = 'best_distance_tie';
    case DISTANCE_MARGIN_TOO_SMALL = 'distance_margin_too_small';
}
