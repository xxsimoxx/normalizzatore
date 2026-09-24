<?php

declare(strict_types=1);

namespace Normalizzatore\Csv;

use RuntimeException;
use SplFileObject;

final class CsvReader
{
    private const SUPPORTED_DELIMITERS = [',', ';', "\t"];

    public function read(string $path, string $delimiter = ','): CsvDocument
    {
        if (!in_array($delimiter, self::SUPPORTED_DELIMITERS, true)) {
            throw new \InvalidArgumentException('CSV delimiter must be comma, semicolon, or tab.');
        }

        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('CSV file is not readable: %s', $path));
        }

        $file = new SplFileObject($path, 'rb');
        $header = $this->readRecord($file, $delimiter);

        if ($header === false) {
            throw new RuntimeException('CSV file is empty and has no header row.');
        }

        if (isset($header[0])) {
            $header[0] = $this->removeUtf8Bom($header[0]);
        }

        $rows = [];
        while (($row = $this->readRecord($file, $delimiter)) !== false) {
            $rows[] = $row;
        }

        return new CsvDocument($header, $rows);
    }

    /** @return list<string>|false */
    private function readRecord(SplFileObject $file, string $delimiter): array|false
    {
        $record = $file->fgetcsv($delimiter, '"', '');

        if ($record === false) {
            return false;
        }

        // fgetcsv returns [null] at EOF for an empty file or after a trailing newline.
        if ($record === [null] && $file->eof()) {
            return false;
        }

        // PHP reports empty CSV cells as null on some versions.
        return array_map(static fn (mixed $value): string => $value === null ? '' : (string) $value, $record);
    }

    private function removeUtf8Bom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }
}
