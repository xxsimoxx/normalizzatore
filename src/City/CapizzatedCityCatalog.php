<?php

declare(strict_types=1);

namespace Normalizzatore\City;

use InvalidArgumentException;
use RuntimeException;
use SplFileObject;
use UnexpectedValueException;

/**
 * The authoritative list of capizzated cities. Membership depends on city name only;
 * province values are retained as source metadata and do not take part in lookup.
 */
final readonly class CapizzatedCityCatalog
{
    /** @var array<string, CapizzatedCity> */
    private array $citiesByKey;

    /**
     * @param list<CapizzatedCity> $cities
     */
    public function __construct(array $cities)
    {
        $citiesByKey = [];
        foreach ($cities as $city) {
            if (!$city instanceof CapizzatedCity) {
                throw new InvalidArgumentException('The city catalog accepts only CapizzatedCity values.');
            }

            $key = self::normalizeName($city->name);
            if ($key === '') {
                throw new InvalidArgumentException('Capizzated city names cannot be empty.');
            }
            if (isset($citiesByKey[$key])) {
                throw new InvalidArgumentException(sprintf('Duplicate capizzated city name: %s.', $city->name));
            }

            $citiesByKey[$key] = $city;
        }

        $this->citiesByKey = $citiesByKey;
    }

    public static function fromTsvFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException(sprintf('Capizzated city list is not readable: %s', $path));
        }

        $file = new SplFileObject($path, 'rb');
        $cities = [];
        foreach ($file as $lineNumber => $line) {
            if (!is_string($line)) {
                continue;
            }

            $line = rtrim($line, "\r\n");
            if ($line === '') {
                if ($file->eof()) {
                    continue;
                }

                throw new UnexpectedValueException(sprintf('Empty row in capizzated city list at line %d.', $lineNumber + 1));
            }

            if ($lineNumber === 0 && str_starts_with($line, "\xEF\xBB\xBF")) {
                $line = substr($line, 3);
            }

            $columns = explode("\t", $line);
            if (count($columns) !== 2 || $columns[0] === '' || $columns[1] === '') {
                throw new UnexpectedValueException(sprintf(
                    'Invalid capizzated city row at line %d; expected city and province separated by one TAB.',
                    $lineNumber + 1,
                ));
            }

            $cities[] = new CapizzatedCity($columns[0], $columns[1]);
        }

        if ($cities === []) {
            throw new UnexpectedValueException('Capizzated city list contains no cities.');
        }

        return new self($cities);
    }

    public function isCapizzated(string $cityName): bool
    {
        return $this->find($cityName) !== null;
    }

    public function find(string $cityName): ?CapizzatedCity
    {
        return $this->citiesByKey[self::normalizeName($cityName)] ?? null;
    }

    /** @return list<CapizzatedCity> */
    public function all(): array
    {
        return array_values($this->citiesByKey);
    }

    private static function normalizeName(string $name): string
    {
        $collapsed = preg_replace('/[\p{Z}\x09-\x0D\x{0085}]+/u', ' ', $name);
        if ($collapsed === null) {
            throw new InvalidArgumentException('City name contains invalid UTF-8.');
        }

        return mb_strtoupper(trim($collapsed), 'UTF-8');
    }
}
