<?php

declare(strict_types=1);

namespace Normalizzatore\City;

use InvalidArgumentException;

/** Evidence for a unique city-name match; province is retained as locality metadata. */
final readonly class FuzzyCityMatchEvidence
{
    public function __construct(
        public string $sourceCity,
        public CityCandidate $candidate,
        public int $distance,
        public ?int $secondBestDistance,
        public ?int $margin,
        public bool $provinceScoped = false,
    ) {
        if (trim($sourceCity) === '' || $distance < 1 || ($secondBestDistance !== null && $secondBestDistance < $distance)
            || ($margin !== null && $margin < 0)
            || ($secondBestDistance === null && $margin !== null)
            || ($secondBestDistance !== null && $margin !== $secondBestDistance - $distance)) {
            throw new InvalidArgumentException('Fuzzy city match evidence is inconsistent.');
        }
    }
}
