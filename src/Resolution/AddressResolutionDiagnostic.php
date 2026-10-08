<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

enum AddressResolutionDiagnostic: string
{
    case NO_TERRITORIAL_MATCH = 'no_territorial_match';
    case NO_STREET_MATCH = 'no_street_match';
    case MULTIPLE_TERRITORIAL_CAPS = 'multiple_territorial_caps';
    case MULTIPLE_STREET_CAPS = 'multiple_street_caps';
    case MULTIPLE_STREET_INTERPRETATIONS = 'multiple_street_interpretations';
    case INDETERMINATE_EVIDENCE = 'indeterminate_evidence';
    case SPECIAL_CAP_PRESENT = 'special_cap_present';
    case NO_ADDRESS_CANDIDATES = 'no_address_candidates';
    case EMPTY_ADDRESS_INPUT = 'empty_address_input';
    case FUZZY_NOT_APPLICABLE = 'fuzzy_not_applicable';
    case FUZZY_ABBREVIATION_MATCH = 'fuzzy_abbreviation_match';
    case FUZZY_TYPO_MATCH = 'fuzzy_typo_match';
    case FUZZY_AMBIGUOUS = 'fuzzy_ambiguous';
    case FUZZY_NO_MATCH = 'fuzzy_no_match';
    case FUZZY_PROVINCE_CONFLICT = 'fuzzy_province_conflict';
    case FUZZY_AMBIGUOUS_LOCALITY = 'fuzzy_ambiguous_locality';
    case FUZZY_NO_LOCALITY = 'fuzzy_no_locality';
    case FUZZY_CITY_MATCH = 'fuzzy_city_match';
    case FUZZY_CITY_AMBIGUOUS = 'fuzzy_city_ambiguous';
    case FUZZY_CITY_NO_MATCH = 'fuzzy_city_no_match';
    case FUZZY_CITY_NOT_APPLICABLE = 'fuzzy_city_not_applicable';
    case TERRITORIAL_LOCATION_CONFLICT = 'territorial_location_conflict';
    case TERRITORIAL_STREET_RECOVERY = 'territorial_street_recovery';
    case FRAZIONE_RECOGNIZED = 'frazione_riconosciuta';
    case FRAZIONE_AMBIGUOUS = 'frazione_ambigua';
    case FRAZIONE_UNVERIFIED = 'frazione_non_verificabile';
    case FUZZY_FRAZIONE_APPLIED = 'fuzzy_frazione_applicato';
    case FUZZY_FRAZIONE_SUGGESTED = 'fuzzy_frazione_suggerito';
    case FUZZY_FRAZIONE_AMBIGUOUS = 'fuzzy_frazione_ambiguo';
    case FUZZY_FRAZIONE_UNVERIFIED = 'fuzzy_frazione_non_verificabile';
    case FUZZY_FRAZIONE_NO_MATCH = 'fuzzy_frazione_non_trovato';
    case FUZZY_FRAZIONE_BLOCKED = 'fuzzy_frazione_bloccato';
}
