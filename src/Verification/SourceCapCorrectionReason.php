<?php

declare(strict_types=1);

namespace Normalizzatore\Verification;

enum SourceCapCorrectionReason: string
{
    case SOURCE_CAP_MISMATCH = 'source_cap_mismatch';
    case SOURCE_CAP_MISSING = 'source_cap_missing';
    case SOURCE_CAP_INVALID = 'source_cap_invalid';
}
