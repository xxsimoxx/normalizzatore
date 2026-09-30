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
}
