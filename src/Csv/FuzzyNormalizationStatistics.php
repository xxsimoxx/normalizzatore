<?php

declare(strict_types=1);

namespace Normalizzatore\Csv;

/**
 * Aggregated fuzzy counts with provider and nominal-matcher stages kept separate.
 * Provider calls partition into geographic rejections and nominal outcomes; the
 * resolved count is a subset of nominal matches after the normal CAP resolver.
 */
final readonly class FuzzyNormalizationStatistics
{
    public function __construct(
        public int $providerCalls,
        public int $providerGeographicNotApplicable,
        public int $abbreviationMatches,
        public int $typoMatches,
        public int $ambiguous,
        public int $noMatch,
        public int $nominalNotApplicable,
        public int $resolved,
    ) {
    }

    public function toText(): string
    {
        return sprintf(
            "Fuzzy:\n  Ricerche candidate (provider invocato): %d\n  Provider senza ambito geografico utilizzabile: %d\n  Match nominali per abbreviazione: %d\n  Match nominali per typo: %d\n  Ambigui nominali: %d\n  Nessun nome compatibile: %d\n  Matching nominale non applicabile: %d\n  Risolti dopo il resolver CAP: %d\n",
            $this->providerCalls,
            $this->providerGeographicNotApplicable,
            $this->abbreviationMatches,
            $this->typoMatches,
            $this->ambiguous,
            $this->noMatch,
            $this->nominalNotApplicable,
            $this->resolved,
        );
    }
}
