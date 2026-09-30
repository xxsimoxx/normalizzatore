<?php

declare(strict_types=1);

namespace Normalizzatore\Directory;

interface AddressDirectoryInterface
{
    /** @return list<DirectoryEntry> */
    public function findByStreetCityProvince(string $street, string $city, string $province): array;

    /** @return list<TerritorialEntry> Aggregated by original city, province, and CAP. */
    public function findTerritorialEntries(string $city): array;
}
