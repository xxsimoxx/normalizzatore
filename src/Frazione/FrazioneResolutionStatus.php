<?php

declare(strict_types=1);

namespace Normalizzatore\Frazione;

enum FrazioneResolutionStatus: string
{
    case MATCH = 'MATCH';
    case AMBIGUOUS = 'AMBIGUOUS';
    case NO_MATCH = 'NO_MATCH';
    case INDETERMINATE = 'INDETERMINATE';
    case NOT_APPLICABLE = 'NOT_APPLICABLE';
}
