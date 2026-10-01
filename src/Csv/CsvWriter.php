<?php

declare(strict_types=1);

namespace Normalizzatore\Csv;

use RuntimeException;

final class CsvWriter
{
    /** @param resource $stream @param list<string> $row */
    public function writeRecord($stream, array $row, string $delimiter): void
    {
        if (fputcsv($stream, $row, $delimiter, '"', '', "\n") === false) {
            throw new RuntimeException('Unable to write a CSV record.');
        }
    }
}
