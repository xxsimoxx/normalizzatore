<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use InvalidArgumentException;
use Normalizzatore\Directory\TerritorialEntry;

final readonly class TerritorialResolution
{
    /**
     * @param list<string> $candidateCaps
     * @param list<TerritorialEntry> $evidence
     * @param list<TerritorialResolutionDiagnostic> $diagnostics
     */
    public function __construct(
        public TerritorialResolutionStatus $status,
        public array $candidateCaps,
        public ?string $resolvedCap,
        public array $evidence,
        public array $diagnostics,
    ) {
        foreach ([$candidateCaps, $evidence, $diagnostics] as $list) {
            if (!array_is_list($list)) {
                throw new InvalidArgumentException('Territorial resolution collections must be lists.');
            }
        }

        $sortedCaps = $candidateCaps;
        sort($sortedCaps, SORT_STRING);
        if ($candidateCaps !== $sortedCaps || count(array_unique($candidateCaps)) !== count($candidateCaps)) {
            throw new InvalidArgumentException('Candidate CAPs must be unique and sorted lexicographically.');
        }
        foreach ($candidateCaps as $cap) {
            if (preg_match('/\A[0-9]{5}\z/', $cap) !== 1) {
                throw new InvalidArgumentException('Candidate CAPs must contain exactly five ASCII digits.');
            }
        }
        foreach ($evidence as $entry) {
            if (!$entry instanceof TerritorialEntry) {
                throw new InvalidArgumentException('Territorial evidence must contain TerritorialEntry values.');
            }
        }
        foreach ($diagnostics as $diagnostic) {
            if (!$diagnostic instanceof TerritorialResolutionDiagnostic) {
                throw new InvalidArgumentException('Territorial diagnostics must use TerritorialResolutionDiagnostic values.');
            }
        }
        if (count(array_unique(array_map(static fn (TerritorialResolutionDiagnostic $item): string => $item->value, $diagnostics))) !== count($diagnostics)) {
            throw new InvalidArgumentException('Territorial diagnostics must be unique.');
        }

        if ($status === TerritorialResolutionStatus::RESOLVED
            && (count($candidateCaps) !== 1 || $resolvedCap !== $candidateCaps[0])) {
            throw new InvalidArgumentException('A resolved territorial result must expose exactly one resolved CAP.');
        }
        if ($status !== TerritorialResolutionStatus::RESOLVED && $resolvedCap !== null) {
            throw new InvalidArgumentException('Only a resolved territorial result may expose a resolved CAP.');
        }
        if ($status === TerritorialResolutionStatus::NO_MATCH && ($evidence !== [] || $candidateCaps !== [])) {
            throw new InvalidArgumentException('A territorial no-match result cannot contain evidence or candidate CAPs.');
        }
        if ($status === TerritorialResolutionStatus::AMBIGUOUS && count($candidateCaps) < 2) {
            throw new InvalidArgumentException('An ambiguous territorial result needs multiple ordinary CAPs.');
        }
        if ($status === TerritorialResolutionStatus::INDETERMINATE && $evidence === []) {
            throw new InvalidArgumentException('An indeterminate territorial result needs directory evidence.');
        }
    }

    public function isResolved(): bool
    {
        return $this->status === TerritorialResolutionStatus::RESOLVED;
    }
}
