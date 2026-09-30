<?php

declare(strict_types=1);

namespace Normalizzatore\Normalization;

enum NormalizedFieldStatus: string
{
    case CONFIRMED = 'confirmed';
    case SYNTAX_NORMALIZED = 'syntax_normalized';
    case DIRECTORY_CORRECTION = 'directory_correction';
    case AMBIGUOUS = 'ambiguous';
    case UNVERIFIABLE = 'unverifiable';
    case MISSING = 'missing';
}
