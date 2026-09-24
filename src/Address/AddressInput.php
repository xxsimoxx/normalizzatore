<?php

declare(strict_types=1);

namespace Normalizzatore\Address;

final readonly class AddressInput
{
    public function __construct(
        public string $vianum,
        public ?string $cap,
        public ?string $city,
        public ?string $province,
    ) {
    }
}
