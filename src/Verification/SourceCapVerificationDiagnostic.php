<?php

declare(strict_types=1);

namespace Normalizzatore\Verification;

enum SourceCapVerificationDiagnostic: string
{
    case SOURCE_CAP_MISSING = 'source_cap_missing';
    case SOURCE_CAP_INVALID = 'source_cap_invalid';
    case SOURCE_CAP_DIFFERS = 'source_cap_differs';
    case RESOLUTION_UNVERIFIABLE = 'resolution_unverifiable';
    case SOURCE_CAP_PRESENT_AMONG_CANDIDATES = 'source_cap_present_among_candidates';
    case SOURCE_CAP_ABSENT_FROM_CANDIDATES = 'source_cap_absent_from_candidates';
}
