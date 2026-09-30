<?php

declare(strict_types=1);

namespace Normalizzatore\Normalization;

use InvalidArgumentException;

final readonly class FieldCorrection
{
    public function __construct(
        public NormalizedFieldName $field,
        public string $originalValue,
        public string $proposedValue,
        public FieldCorrectionReason $reason,
        public NormalizationOrigin $origin,
    ) {
        if ($originalValue === $proposedValue) {
            throw new InvalidArgumentException('A field correction must change the original value.');
        }
        if ($origin === NormalizationOrigin::ORIGINAL) {
            throw new InvalidArgumentException('A correction must have a normalization origin.');
        }
        if (($reason === FieldCorrectionReason::WHITESPACE_NORMALIZATION) !== ($origin === NormalizationOrigin::SYNTAX)) {
            throw new InvalidArgumentException('Correction reason and origin are inconsistent.');
        }
    }
}
