<?php

declare(strict_types=1);

namespace Normalizzatore\Directory;

final readonly class DbfRecord
{
    public function __construct(
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
