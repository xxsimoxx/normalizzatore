<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use InvalidArgumentException;

/** Immutable policy values for the first, conservative street fuzzy matchers. */
final readonly class FuzzyStreetMatchingOptions
{
    public function __construct(
        public int $minimumTypoTokenLength = 4,
        public int $maximumTypoDistance = 1,
        public int $minimumDistanceMargin = 2,
    ) {
        if ($minimumTypoTokenLength < 1) {
            throw new InvalidArgumentException('Minimum typo token length must be positive.');
        }
        if ($maximumTypoDistance < 1) {
            throw new InvalidArgumentException('Maximum typo distance must be positive.');
        }
        if ($minimumDistanceMargin < 1) {
            throw new InvalidArgumentException('Minimum typo distance margin must be positive.');
        }
    }
}
