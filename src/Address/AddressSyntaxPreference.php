<?php

declare(strict_types=1);

namespace Normalizzatore\Address;

use InvalidArgumentException;

/** Explicit syntactic preference; it never removes or reorders parsed candidates. */
final readonly class AddressSyntaxPreference
{
    public function __construct(
        public ?AddressCandidate $preferredCandidate,
        public AddressSyntaxPreferenceReason $reason,
    ) {
        $isPreferenceReason = in_array($reason, [
            AddressSyntaxPreferenceReason::FINAL_CIVIC_NUMBER,
            AddressSyntaxPreferenceReason::FINAL_CIVIC_AFTER_COMMA,
            AddressSyntaxPreferenceReason::FINAL_CIVIC_WITH_SUFFIX,
            AddressSyntaxPreferenceReason::FINAL_CIVIC_AFTER_NUMBERED_STATE_ROAD,
            AddressSyntaxPreferenceReason::NUMERIC_STREET_DATE,
        ], true);
        if ($isPreferenceReason !== ($preferredCandidate !== null)) {
            throw new InvalidArgumentException('A preferred candidate and its reason must be provided together.');
        }
    }

    public function hasPreferredCandidate(): bool
    {
        return $this->preferredCandidate !== null;
    }
}
