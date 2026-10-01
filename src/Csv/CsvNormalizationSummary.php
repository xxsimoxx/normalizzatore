<?php

declare(strict_types=1);

namespace Normalizzatore\Csv;

final readonly class CsvNormalizationSummary
{
    public function __construct(
        public int $processed,
        public int $resolved,
        public int $ambiguous,
        public int $unresolved,
        public int $rowsWithCorrections,
        public float $elapsedSeconds,
    ) {
    }

    public function toText(): string
    {
        return sprintf(
            "Elaborate: %d righe\nRisolte: %d\nAmbigue: %d\nNon risolte: %d\nCorrezioni: %d\nTempo: %.2f s\n",
            $this->processed,
            $this->resolved,
            $this->ambiguous,
            $this->unresolved,
            $this->rowsWithCorrections,
            $this->elapsedSeconds,
        );
    }
}
