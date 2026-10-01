<?php

declare(strict_types=1);

namespace Normalizzatore\Application;

use Normalizzatore\Address\AddressInput;
use Normalizzatore\Normalization\AddressFieldNormalizer;
use Normalizzatore\Resolution\AddressResolutionOrchestrator;
use Normalizzatore\Verification\SourceCapVerifier;

/** Integrates resolution, source-CAP verification and field normalization for one input row. */
final readonly class AddressProcessor
{
    public function __construct(
        private AddressResolutionOrchestrator $resolutionOrchestrator,
        private SourceCapVerifier $sourceCapVerifier,
        private AddressFieldNormalizer $fieldNormalizer,
    ) {
    }

    public function process(AddressInput $input): AddressProcessingResult
    {
        $resolution = $this->resolutionOrchestrator->resolve($input);
        $capVerification = $this->sourceCapVerifier->verify($input->cap ?? '', $resolution);
        $fieldNormalization = $this->fieldNormalizer->normalize($input, $resolution);

        return new AddressProcessingResult($input, $resolution, $capVerification, $fieldNormalization);
    }
}
