<?php

declare(strict_types=1);

namespace Normalizzatore\Verification;

use InvalidArgumentException;

/** A proposed CAP value; it never changes the input value. */
final readonly class SourceCapCorrection
{
    public function __construct(
        public string $sourceCapOriginal,
        public string $proposedCap,
        public SourceCapCorrectionReason $reason,
    ) {
        if (preg_match('/\A[0-9]{5}\z/', $proposedCap) !== 1) {
            throw new InvalidArgumentException('A proposed CAP must contain exactly five ASCII digits.');
        }
    }
}
