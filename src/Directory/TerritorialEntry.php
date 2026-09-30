<?php

declare(strict_types=1);

namespace Normalizzatore\Directory;

use InvalidArgumentException;

/** A city/province/CAP group from the directory, with its represented row count. */
final readonly class TerritorialEntry
{
    public function __construct(
        public string $city,
        public string $province,
        public string $cap,
        public int $recordCount,
    ) {
        if ($recordCount < 1) {
            throw new InvalidArgumentException('A territorial entry must represent at least one directory row.');
        }
    }
}
