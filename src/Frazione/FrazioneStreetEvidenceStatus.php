<?php

declare(strict_types=1);

namespace Normalizzatore\Frazione;

enum FrazioneStreetEvidenceStatus: string
{
    case EXACT_STREET = 'EXACT_STREET';
    case CIVIC_COMPATIBLE = 'CIVIC_COMPATIBLE';
    case STREET_NOT_FOUND = 'STREET_NOT_FOUND';
    case CIVIC_NOT_COMPATIBLE = 'CIVIC_NOT_COMPATIBLE';
    case CIVIC_INDETERMINATE = 'CIVIC_INDETERMINATE';
    case PARSER_AMBIGUOUS = 'PARSER_AMBIGUOUS';
}
