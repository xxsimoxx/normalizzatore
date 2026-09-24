<?php

declare(strict_types=1);

namespace Normalizzatore\Address;

final readonly class HouseNumber
{
    public function __construct(
        public string $number,
        public ?string $separator = null,
        public ?string $suffix = null,
        public ?string $rangeEnd = null,
        public ?string $raw = null,
    ) {
    }
}
