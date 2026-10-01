<?php

declare(strict_types=1);

namespace Normalizzatore\Address;

enum AddressSyntaxPreferenceReason: string
{
    case FINAL_CIVIC_NUMBER = 'final_civic_number';
    case FINAL_CIVIC_AFTER_COMMA = 'final_civic_after_comma';
    case FINAL_CIVIC_WITH_SUFFIX = 'final_civic_with_suffix';
    case FINAL_CIVIC_AFTER_NUMBERED_STATE_ROAD = 'final_civic_after_numbered_state_road';
    case NUMERIC_STREET_DATE = 'numeric_street_date';
    case NO_CANDIDATES = 'no_candidates';
    case NO_CIVIC_NUMBER = 'no_civic_number';
    case EXPLICIT_SNC = 'explicit_snc';
    case EQUIVALENT_CANDIDATES = 'equivalent_candidates';
    case INCOMPLETE_STREET_NAME = 'incomplete_street_name';
    case AMBIGUOUS_NUMERIC_BOUNDARY = 'ambiguous_numeric_boundary';
    case COMPLEX_CIVIC_DETAILS = 'complex_civic_details';
}
