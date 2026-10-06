<?php

declare(strict_types=1);

namespace Normalizzatore\Normalization;

enum FieldCorrectionReason: string
{
    case WHITESPACE_NORMALIZATION = 'whitespace_normalization';
    case DIRECTORY_CANONICAL_VALUE = 'directory_canonical_value';
    case FUZZY_ABBREVIATION_EXPANSION = 'fuzzy_abbreviation_expansion';
    case FUZZY_TYPO_CORRECTION = 'fuzzy_typo_correction';
}
