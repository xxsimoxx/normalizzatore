<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

enum FuzzyStreetMatchKind: string
{
    case ABBREVIATION = 'abbreviation';
    case TYPO = 'typo';
}
