<?php

declare(strict_types=1);

namespace Normalizzatore\Frazione;

/** One source record from the optional frazioni.tsv catalog. */
final readonly class FrazioneEntry
{
    public function __construct(
        public string $cap,
        public string $comune,
        public string $frazione,
        public string $provincia,
        public string $tipo,
        public int $lineNumber,
    ) {
    }
}
