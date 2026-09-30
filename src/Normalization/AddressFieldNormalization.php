<?php

declare(strict_types=1);

namespace Normalizzatore\Normalization;

use Normalizzatore\Resolution\AddressResolution;

/** Field outcomes plus references to the original source and resolution evidence. */
final readonly class AddressFieldNormalization
{
    public function __construct(
        public string $sourceVianum,
        public AddressResolution $resolutionEvidence,
        public NormalizedField $street,
        public NormalizedField $houseNumber,
        public NormalizedField $civicDetails,
        public NormalizedField $city,
        public NormalizedField $province,
    ) {
        foreach ([
            [$street, NormalizedFieldName::STREET],
            [$houseNumber, NormalizedFieldName::HOUSE_NUMBER],
            [$civicDetails, NormalizedFieldName::CIVIC_DETAILS],
            [$city, NormalizedFieldName::CITY],
            [$province, NormalizedFieldName::PROVINCE],
        ] as [$field, $expected]) {
            if ($field->field !== $expected) {
                throw new \InvalidArgumentException('Normalized field is assigned to the wrong result property.');
            }
        }
    }

    /** @return list<FieldCorrection> */
    public function suggestedCorrections(): array
    {
        $corrections = [];
        foreach ([$this->street, $this->houseNumber, $this->civicDetails, $this->city, $this->province] as $field) {
            if ($field->correction !== null) {
                $corrections[] = $field->correction;
            }
        }

        return $corrections;
    }
}
