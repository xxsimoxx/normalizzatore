<?php

declare(strict_types=1);

namespace Normalizzatore\Address;

final readonly class AddressCandidate
{
    public function __construct(
        public string $streetName,
        public ?HouseNumber $houseNumber,
        public string $trailingInformation,
    ) {
    }
}
