<?php

declare(strict_types=1);

namespace Normalizzatore\Frazione;

use InvalidArgumentException;

/** Conservative nominal fuzzy policy for fraction names. */
final readonly class FuzzyFrazioneMatchingOptions
{
    public function __construct(public int $minimumTokenLength = 5)
    {
        if ($minimumTokenLength < 1) {
            throw new InvalidArgumentException('Minimum fraction token length must be positive.');
        }
    }
}
