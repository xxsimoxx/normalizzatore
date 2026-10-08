<?php

declare(strict_types=1);

namespace Normalizzatore\Normalization;

enum FieldCorrectionReason: string
{
    case WHITESPACE_NORMALIZATION = 'whitespace_normalization';
    case DIRECTORY_CANONICAL_VALUE = 'directory_canonical_value';
    case FUZZY_ABBREVIATION_EXPANSION = 'fuzzy_abbreviation_expansion';
    case FUZZY_TYPO_CORRECTION = 'fuzzy_typo_correction';
    case PROVINCE_COMPLETION = 'province_completion';
    case TERRITORIAL_PROVINCE_CORRECTION = 'territorial_province_correction';
    case FUZZY_CITY_CORRECTION = 'fuzzy_city_correction';
    case FUZZY_CITY_PROVINCE_RECONCILIATION = 'fuzzy_city_province_reconciliation';
    case TERRITORIAL_CITY_RECOVERY = 'territorial_city_recovery';
    case TERRITORIAL_PROVINCE_RECOVERY = 'territorial_province_recovery';
    case TERRITORIAL_STREET_RECOVERY = 'territorial_street_recovery';
    case FRAZIONE_TO_COMUNE = 'frazione_to_comune';
    case FUZZY_FRAZIONE_TO_COMUNE = 'fuzzy_frazione_to_comune';
}
