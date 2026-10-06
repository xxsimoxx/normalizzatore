<?php

declare(strict_types=1);

namespace Normalizzatore\Directory;

/** Read-only statistics captured while preparing the connection-local fuzzy catalog. */
final readonly class FuzzyStreetCatalogStatistics
{
    /** @param array<string, int> $excludedByPrefix */
    public function __construct(
        public int $sourceLogicalNames,
        public int $includedSourceNames,
        public int $excludedSourceNames,
        public int $canonicalLogicalNames,
        public array $excludedByPrefix,
        public array $tokenCountDistribution,
    ) {
    }

    public function coveragePercentage(): float
    {
        return $this->sourceLogicalNames === 0 ? 0.0 : 100 * $this->includedSourceNames / $this->sourceLogicalNames;
    }
}
