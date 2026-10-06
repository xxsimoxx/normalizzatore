<?php

declare(strict_types=1);

namespace Normalizzatore\Csv;

final readonly class NormalizeOptions
{
    public function __construct(
        public string $inputPath,
        public string $outputPath,
        public string $delimiter = ';',
        public bool $fuzzy = false,
    )
    {
    }
}
