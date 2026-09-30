<?php

declare(strict_types=1);

namespace Normalizzatore\Address;

use Normalizzatore\City\CapizzatedCityCatalog;

/** Selects the future resolution strategy using only the input city. */
final readonly class AddressStrategyClassifier
{
    public function __construct(
        private CapizzatedCityCatalog $cityCatalog,
    ) {
    }

    public function classify(AddressInput $input): AddressResolutionStrategy
    {
        return $this->cityCatalog->isCapizzated($input->city ?? '')
            ? AddressResolutionStrategy::STREET_BASED
            : AddressResolutionStrategy::TERRITORIAL;
    }
}
