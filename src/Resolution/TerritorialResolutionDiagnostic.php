<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

enum TerritorialResolutionDiagnostic: string
{
    case NON_ORDINARY_CAP = 'non_ordinary_cap';
    case SPECIAL_CAP_WITH_ORDINARY_CAP = 'special_cap_with_ordinary_cap';
    case MULTIPLE_ORDINARY_CAPS = 'multiple_ordinary_caps';
}
