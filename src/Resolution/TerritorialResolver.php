<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use InvalidArgumentException;
use Normalizzatore\Directory\TerritorialEntry;

/** Resolves a city-level CAP from already retrieved directory evidence. */
final class TerritorialResolver
{
    /**
     * @param list<TerritorialEntry> $entries
     */
    public function resolve(array $entries): TerritorialResolution
    {
        if (!array_is_list($entries)) {
            throw new InvalidArgumentException('Territorial entries must be provided as a list.');
        }
        foreach ($entries as $entry) {
            if (!$entry instanceof TerritorialEntry) {
                throw new InvalidArgumentException('Territorial entries must contain TerritorialEntry values.');
            }
        }

        if ($entries === []) {
            return new TerritorialResolution(TerritorialResolutionStatus::NO_MATCH, [], null, [], []);
        }

        $evidence = $entries;
        usort($evidence, static fn (TerritorialEntry $left, TerritorialEntry $right): int =>
            [$left->city, $left->province, $left->cap, $left->recordCount]
            <=> [$right->city, $right->province, $right->cap, $right->recordCount]);

        $caps = [];
        $hasNonOrdinaryCap = false;
        foreach ($entries as $entry) {
            if (preg_match('/\A[0-9]{5}\z/', $entry->cap) === 1) {
                $caps['cap:' . $entry->cap] = true;
            } else {
                $hasNonOrdinaryCap = true;
            }
        }
        $candidateCaps = array_map(static fn (string $key): string => substr($key, 4), array_keys($caps));
        sort($candidateCaps, SORT_STRING);

        $diagnostics = [];
        if ($hasNonOrdinaryCap) {
            $diagnostics[] = TerritorialResolutionDiagnostic::NON_ORDINARY_CAP;
        }
        if ($hasNonOrdinaryCap && $candidateCaps !== []) {
            $diagnostics[] = TerritorialResolutionDiagnostic::SPECIAL_CAP_WITH_ORDINARY_CAP;
        }

        if (count($candidateCaps) > 1) {
            $diagnostics[] = TerritorialResolutionDiagnostic::MULTIPLE_ORDINARY_CAPS;
            return new TerritorialResolution(TerritorialResolutionStatus::AMBIGUOUS, $candidateCaps, null, $evidence, $diagnostics);
        }
        if ($candidateCaps === [] || $hasNonOrdinaryCap) {
            return new TerritorialResolution(TerritorialResolutionStatus::INDETERMINATE, $candidateCaps, null, $evidence, $diagnostics);
        }

        return new TerritorialResolution(TerritorialResolutionStatus::RESOLVED, $candidateCaps, $candidateCaps[0], $evidence, []);
    }
}
