<?php

declare(strict_types=1);

namespace Normalizzatore\Address;

final readonly class ParsedAddress
{
    /**
     * @param list<AddressCandidate> $candidates
     */
    public function __construct(
        public AddressInput $input,
        public string $normalizedVianum,
        public array $candidates,
        /** True only when SNC explicitly states that there is no civic number. */
        public bool $hasNoHouseNumber,
        public ?AddressSyntaxPreference $syntaxPreference = null,
    ) {
    }
}
