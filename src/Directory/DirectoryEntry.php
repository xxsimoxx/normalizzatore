<?php

declare(strict_types=1);

namespace Normalizzatore\Directory;

final readonly class DirectoryEntry
{
    public function __construct(
        public int $id,
        public string $vianum,
        public string $cap,
        public string $citta,
        public string $pr,
        public string $pariDispa,
        public string $civicoDa,
        public string $civicoA,
    ) {
    }
}
