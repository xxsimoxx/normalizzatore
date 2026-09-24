<?php

declare(strict_types=1);

namespace Normalizzatore\Address;

final readonly class HouseNumber
{
    public function __construct(
        public string $number,
    ) {
    }
}
