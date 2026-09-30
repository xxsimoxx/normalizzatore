<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use InvalidArgumentException;
use Normalizzatore\Directory\DirectoryEntry;

final readonly class CapResolution
{
    /**
     * @param list<string> $candidateCaps
     * @param list<DirectoryEntry> $applicableEntries
     * @param list<DirectoryEntry> $unsupportedEntries
     * @param list<CapResolutionDiagnostic> $diagnostics
     */
    public function __construct(
        public CapResolutionStatus $status,
        public CapResolutionBasis $basis,
        public array $candidateCaps,
        public array $applicableEntries,
        public array $unsupportedEntries,
        public array $diagnostics,
    ) {
        foreach ([$candidateCaps, $applicableEntries, $unsupportedEntries, $diagnostics] as $list) {
            if (!array_is_list($list)) {
                throw new InvalidArgumentException('CAP resolution collections must be lists.');
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
        foreach ([...$applicableEntries, ...$unsupportedEntries] as $entry) {
            if (!$entry instanceof DirectoryEntry) {
                throw new InvalidArgumentException('CAP resolution evidence must contain DirectoryEntry values.');
            }
        }
        foreach ($diagnostics as $diagnostic) {
            if (!$diagnostic instanceof CapResolutionDiagnostic) {
                throw new InvalidArgumentException('CAP resolution diagnostics must use CapResolutionDiagnostic values.');
            }
        }
        if (count(array_unique(array_map(static fn (CapResolutionDiagnostic $diagnostic): string => $diagnostic->value, $diagnostics))) !== count($diagnostics)) {
            throw new InvalidArgumentException('CAP resolution diagnostics must be unique.');
        }

        if ($status === CapResolutionStatus::RESOLVED && (count($candidateCaps) !== 1 || $basis === CapResolutionBasis::NONE)) {
            throw new InvalidArgumentException('A resolved CAP result needs exactly one candidate CAP and a resolution basis.');
        }
        if ($status === CapResolutionStatus::AMBIGUOUS && count($candidateCaps) < 2) {
            throw new InvalidArgumentException('An ambiguous CAP result needs at least two distinct candidate CAPs.');
        }
        if ($status === CapResolutionStatus::NO_MATCH && $candidateCaps !== []) {
            throw new InvalidArgumentException('A no-match result cannot contain candidate CAPs.');
        }
        if ($status === CapResolutionStatus::NO_MATCH && $basis !== CapResolutionBasis::NONE) {
            throw new InvalidArgumentException('A no-match result must use the NONE resolution basis.');
        }
    }

    public function isResolved(): bool
    {
        return $this->status === CapResolutionStatus::RESOLVED;
    }

    public function resolvedCap(): ?string
    {
        return $this->isResolved() && count($this->candidateCaps) === 1
            ? $this->candidateCaps[0]
            : null;
    }
}
