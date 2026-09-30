<?php

declare(strict_types=1);

namespace Normalizzatore\Normalization;

enum NormalizationOrigin: string
{
    case ORIGINAL = 'original';
    case SYNTAX = 'syntax';
    case DIRECTORY = 'directory';
}
