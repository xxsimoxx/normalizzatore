<?php

declare(strict_types=1);

namespace Normalizzatore\Csv;

use Generator;
use SplFileObject;

/** Streaming CSV records with the same parsing rules as CsvReader::read(). */
final readonly class CsvStream
{
    /** @param list<string> $header */
    public function __construct(
        private SplFileObject $file,
        public array $header,
        private string $delimiter,
        private CsvReader $reader,
    ) {
    }

    /** @return Generator<int, list<string>> */
    public function rows(): Generator
    {
        while (($row = $this->reader->readNextRecord($this->file, $this->delimiter)) !== false) {
            yield $row;
        }
    }
}
