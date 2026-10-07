<?php

declare(strict_types=1);

namespace Normalizzatore\City;

use InvalidArgumentException;

/** A logical directory city within one province. */
final readonly class CityCandidate
{
    public function __construct(
        public string $name,
        public string $canonicalKey,
        public string $province,
    ) {
        if (trim($name) === '' || trim($canonicalKey) === '' || preg_match('/\A[A-Za-z]{2}\z/', trim($province)) !== 1) {
            throw new InvalidArgumentException('A city candidate needs a name, canonical key, and two-letter province.');
        }
    }
}
