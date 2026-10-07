<?php

declare(strict_types=1);

namespace Normalizzatore\City;

use InvalidArgumentException;

/** Conservative, independently tunable city typo policy. */
final readonly class FuzzyCityMatchingOptions
{
    public function __construct(
        public int $minimumNameLength = 5,
        public int $maximumDistance = 1,
        public int $minimumDistanceMargin = 2,
    ) {
        if ($minimumNameLength < 5 || $maximumDistance !== 1 || $minimumDistanceMargin < 2) {
            throw new InvalidArgumentException('Fuzzy city matching options must retain the conservative v1 policy.');
        }
    }
}
