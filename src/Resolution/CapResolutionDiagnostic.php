<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

enum CapResolutionDiagnostic: string
{
    case NO_HOUSE_NUMBER = 'no_house_number';
    case CIVIC_DETAIL_PRESENT = 'civic_detail_present';
    case UNSUPPORTED_CIVIC_DETAIL = 'unsupported_civic_detail';
    case INVALID_HOUSE_NUMBER = 'invalid_house_number';
    case UNSUPPORTED_PARITY = 'unsupported_parity';
    case NON_NUMERIC_RANGE = 'non_numeric_range';
    case INVALID_NUMERIC_RANGE = 'invalid_numeric_range';
    case NON_ORDINARY_CAP = 'non_ordinary_cap';
    case NO_APPLICABLE_RANGE = 'no_applicable_range';
    case MULTIPLE_CAPS = 'multiple_caps';
}
