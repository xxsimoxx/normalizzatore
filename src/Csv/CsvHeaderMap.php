<?php

declare(strict_types=1);

namespace Normalizzatore\Csv;

use RuntimeException;

/** Exact, header-based mapping of the four operational source columns. */
final readonly class CsvHeaderMap
{
    /** @param array<string, int> $indexes */
    private function __construct(private array $indexes)
    {
    }

    /** @param list<string> $header @param list<string> $reservedOutputColumns */
    public static function fromHeader(array $header, array $reservedOutputColumns): self
    {
        foreach ($reservedOutputColumns as $column) {
            if (in_array($column, $header, true)) {
                throw new RuntimeException(sprintf('Input already contains reserved output column "%s".', $column));
            }
        }

        $indexes = [];
        foreach (['vianum', 'CAP', 'citta', 'Provincia'] as $required) {
            $matches = [];
            foreach ($header as $index => $name) {
                if ($name === $required) {
                    $matches[] = $index;
                }
            }
            if ($matches === []) {
                throw new RuntimeException(sprintf('Input CSV is missing required column "%s".', $required));
            }
            if (count($matches) !== 1) {
                throw new RuntimeException(sprintf('Input CSV contains duplicate required column "%s".', $required));
            }
            $indexes[$required] = $matches[0];
        }

        return new self($indexes);
    }

    /** @param list<string> $row */
    public function addressInput(array $row): \Normalizzatore\Address\AddressInput
    {
        return new \Normalizzatore\Address\AddressInput(
            $row[$this->indexes['vianum']],
            $row[$this->indexes['CAP']],
            $row[$this->indexes['citta']],
            $row[$this->indexes['Provincia']],
        );
    }
}
