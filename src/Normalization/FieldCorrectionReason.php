<?php

declare(strict_types=1);

namespace Normalizzatore\Normalization;

enum FieldCorrectionReason: string
{
    case WHITESPACE_NORMALIZATION = 'whitespace_normalization';
    case DIRECTORY_CANONICAL_VALUE = 'directory_canonical_value';
}
