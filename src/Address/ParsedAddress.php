<?php

declare(strict_types=1);

namespace Normalizzatore\Address;

final readonly class ParsedAddress
{
    public function __construct(
        public AddressInput $input,
        public string $streetName,
        public ?HouseNumber $houseNumber,
        public string $trailingInformation,
        /** True only when SNC explicitly states that there is no civic number. */
        public bool $hasNoHouseNumber,
    ) {
    }
}
