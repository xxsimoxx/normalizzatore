<?php

declare(strict_types=1);

namespace Normalizzatore\Verification;

enum SourceCapVerificationStatus: string
{
    case MATCH = 'match';
    case MISMATCH = 'mismatch';
    case SOURCE_MISSING = 'source_missing';
    case SOURCE_INVALID = 'source_invalid';
    case UNVERIFIABLE = 'unverifiable';
}
