<?php

declare(strict_types=1);

namespace Normalizzatore\Csv;

/**
 * A parsed CSV file. Rows retain their original positional values as strings.
 */
final readonly class CsvDocument
{
    /**
     * @param list<string> $header
     * @param list<list<string>> $rows
     */
    public function __construct(
        public array $header,
        public array $rows,
    ) {
    }

    public function columnIndex(string $name): ?int
    {
        $index = array_search($name, $this->header, true);

        return $index === false ? null : $index;
    }
}
