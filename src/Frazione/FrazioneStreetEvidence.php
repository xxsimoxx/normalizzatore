<?php

declare(strict_types=1);

namespace Normalizzatore\Frazione;

use InvalidArgumentException;
use Normalizzatore\Resolution\CapResolutionStatus;

/** Exact directory evidence required to corroborate an inter-province fraction mapping. */
final readonly class FrazioneStreetEvidence
{
    /** @param list<string> $directoryCaps */
    public function __construct(
        public FrazioneStreetEvidenceStatus $status,
        public ?string $streetName,
        public ?string $civicNumber,
        public int $exactDirectoryEntries,
        public ?CapResolutionStatus $civicResolutionStatus = null,
        public array $directoryCaps = [],
    ) {
        if ($exactDirectoryEntries < 0 || !array_is_list($directoryCaps)) {
            throw new InvalidArgumentException('Invalid frazione street evidence.');
        }
        foreach ($directoryCaps as $cap) {
            if (!is_string($cap)) {
                throw new InvalidArgumentException('Directory CAP evidence must contain strings.');
            }
        }
        if ($status === FrazioneStreetEvidenceStatus::CIVIC_COMPATIBLE
            && $civicResolutionStatus !== CapResolutionStatus::RESOLVED) {
            throw new InvalidArgumentException('Compatible civic evidence requires a resolved CapResolver result.');
        }
    }
}
