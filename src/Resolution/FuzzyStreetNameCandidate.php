<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use Normalizzatore\Address\TokenizedStreetName;

/** A logical street-name candidate; it contains no directory rows or civic data. */
final readonly class FuzzyStreetNameCandidate
{
    public function __construct(public TokenizedStreetName $streetName)
    {
    }
}
