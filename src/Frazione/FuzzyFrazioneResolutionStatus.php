<?php

declare(strict_types=1);

namespace Normalizzatore\Frazione;

enum FuzzyFrazioneResolutionStatus: string
{
    case APPLIED = 'APPLIED';
    case SUGGESTED = 'SUGGESTED';
    case AMBIGUOUS = 'AMBIGUOUS';
    case INDETERMINATE = 'INDETERMINATE';
    case NO_MATCH = 'NO_MATCH';
    case NOT_APPLICABLE = 'NOT_APPLICABLE';
    case BLOCKED = 'BLOCKED';
}
