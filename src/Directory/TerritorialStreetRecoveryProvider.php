<?php

declare(strict_types=1);

namespace Normalizzatore\Directory;

/** Exact street lookup across localities, used only after typed territorial inconsistency. */
interface TerritorialStreetRecoveryProvider
{
    /** @return list<DirectoryEntry> */
    public function findByStreetAcrossLocalities(string $street, ?string $province = null): array;
}
