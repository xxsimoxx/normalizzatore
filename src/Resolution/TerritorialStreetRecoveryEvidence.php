<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use InvalidArgumentException;

/** Exact street and civic evidence that recovered a unique locality from a territorial conflict. */
final readonly class TerritorialStreetRecoveryEvidence
{
    /** @param list<string> $consideredLocalities */
    public function __construct(
        public string $sourceCity,
        public string $sourceProvince,
        public string $matchedCity,
        public string $matchedProvince,
        public string $matchedStreet,
        public array $consideredLocalities,
    ) {
        if (!array_is_list($consideredLocalities) || trim($matchedCity) === '' || trim($matchedProvince) === '' || trim($matchedStreet) === '') {
            throw new InvalidArgumentException('Territorial street recovery evidence is incomplete.');
        }
        foreach ($consideredLocalities as $locality) {
            if (!is_string($locality) || trim($locality) === '') {
                throw new InvalidArgumentException('Considered territorial localities must be non-empty strings.');
            }
        }
    }
}
