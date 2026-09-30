<?php

declare(strict_types=1);

namespace Normalizzatore\City;

/** One city listed as capizzated, with its source province metadata. */
final readonly class CapizzatedCity
{
    public function __construct(
        public string $name,
        public string $provinceMetadata,
    ) {
    }
}
