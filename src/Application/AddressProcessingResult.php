<?php

declare(strict_types=1);

namespace Normalizzatore\Application;

use InvalidArgumentException;
use Normalizzatore\Address\AddressInput;
use Normalizzatore\Normalization\AddressFieldNormalization;
use Normalizzatore\Normalization\FieldCorrection;
use Normalizzatore\Resolution\AddressResolution;
use Normalizzatore\Resolution\AddressResolutionStatus;
use Normalizzatore\Verification\SourceCapCorrection;
use Normalizzatore\Verification\SourceCapVerification;

/** Immutable aggregate of the independently calculated outcomes for one address input. */
final readonly class AddressProcessingResult
{
    public function __construct(
        public AddressInput $input,
        public AddressResolution $resolution,
        public SourceCapVerification $capVerification,
        public AddressFieldNormalization $fieldNormalization,
    ) {
        if ($fieldNormalization->sourceVianum !== $input->vianum
            || $fieldNormalization->resolutionEvidence !== $resolution) {
            throw new InvalidArgumentException('Field normalization must retain the address resolution used by the processor.');
        }
        if ($capVerification->sourceCapOriginal !== ($input->cap ?? '')
            || $capVerification->resolutionStatus !== $resolution->status
            || $capVerification->resolvedCap !== $resolution->resolvedCap
            || $capVerification->candidateCaps !== $resolution->candidateCaps) {
            throw new InvalidArgumentException('CAP verification must describe the original input and the processor resolution.');
        }
    }

    /** Returns only a CAP resolved independently of the source CAP. */
    public function normalizedCap(): ?string
    {
        return $this->resolution->status === AddressResolutionStatus::RESOLVED
            ? $this->resolution->resolvedCap
            : null;
    }

    /** @return list<FieldCorrection> */
    public function fieldCorrections(): array
    {
        return $this->fieldNormalization->suggestedCorrections();
    }

    public function sourceCapCorrection(): ?SourceCapCorrection
    {
        return $this->capVerification->suggestedCorrection;
    }
}
