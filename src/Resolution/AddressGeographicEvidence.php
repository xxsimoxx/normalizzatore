<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use InvalidArgumentException;
use Normalizzatore\City\FuzzyCityMatchEvidence;

/** Applied geographic reconciliation, kept separate from source CAP verification. */
final readonly class AddressGeographicEvidence
{
    public function __construct(
        public AddressGeographicEvidenceKind $kind,
        public string $sourceCity,
        public string $sourceProvince,
        public string $resolvedCity,
        public string $resolvedProvince,
        public ?FuzzyCityMatchEvidence $fuzzyCityMatch = null,
        public ?TerritorialStreetRecoveryEvidence $streetRecovery = null,
    ) {
        if (trim($resolvedCity) === '' || preg_match('/\A[A-Za-z]{2}\z/', trim($resolvedProvince)) !== 1) {
            throw new InvalidArgumentException('Resolved geographic evidence requires a city and two-letter province.');
        }
        if (($kind === AddressGeographicEvidenceKind::FUZZY_CITY_CORRECTION) !== ($fuzzyCityMatch !== null)
            || ($kind === AddressGeographicEvidenceKind::TERRITORIAL_STREET_RECOVERY) !== ($streetRecovery !== null)) {
            throw new InvalidArgumentException('Geographic evidence details must agree with its kind.');
        }
    }
}
