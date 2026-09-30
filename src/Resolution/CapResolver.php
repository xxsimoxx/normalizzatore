<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use Normalizzatore\Address\AddressCandidate;
use Normalizzatore\Directory\DirectoryEntry;

final class CapResolver
{
    /**
     * Resolve the candidate using only supplied directory entries; this class has no storage dependencies.
     *
     * @param list<DirectoryEntry> $entries
     */
    public function resolve(AddressCandidate $candidate, array $entries): CapResolution
    {
        if ($entries === []) {
            return $this->result(
                CapResolutionStatus::NO_MATCH,
                CapResolutionBasis::NONE,
                [],
                [],
                [],
                [],
            );
        }

        if ($candidate->houseNumber === null) {
            return $this->resolveWithoutHouseNumber($entries);
        }

        if (!$this->isUnsignedInteger($candidate->houseNumber->number)) {
            return $this->result(
                CapResolutionStatus::INDETERMINATE,
                CapResolutionBasis::NONE,
                [],
                [],
                [],
                [CapResolutionDiagnostic::INVALID_HOUSE_NUMBER->value => CapResolutionDiagnostic::INVALID_HOUSE_NUMBER],
            );
        }

        $diagnostics = [];
        $applicableEntries = [];
        $unsupportedEntries = [];
        $candidateCaps = [];
        $hasUnsupportedInterpretation = false;

        foreach ($entries as $entry) {
            $unsupportedReason = $this->rangeOrParityProblem($entry);
            if ($unsupportedReason !== null) {
                $unsupportedEntries[] = $entry;
                $diagnostics[$unsupportedReason->value] = $unsupportedReason;
                $hasUnsupportedInterpretation = true;
                continue;
            }

            if (!$this->matchesRangeAndParity($entry, $candidate->houseNumber->number)) {
                continue;
            }

            $applicableEntries[] = $entry;
            if (!$this->isOrdinaryCap($entry->cap)) {
                $diagnostics[CapResolutionDiagnostic::NON_ORDINARY_CAP->value] = CapResolutionDiagnostic::NON_ORDINARY_CAP;
                continue;
            }

            $candidateCaps[$entry->cap] = $entry->cap;
        }

        if ($candidate->trailingInformation !== '') {
            $diagnostic = $hasUnsupportedInterpretation
                ? CapResolutionDiagnostic::UNSUPPORTED_CIVIC_DETAIL
                : CapResolutionDiagnostic::CIVIC_DETAIL_PRESENT;
            $diagnostics[$diagnostic->value] = $diagnostic;
        }

        $caps = $this->sortedCaps($candidateCaps);
        if (count($caps) > 1) {
            $diagnostics[CapResolutionDiagnostic::MULTIPLE_CAPS->value] = CapResolutionDiagnostic::MULTIPLE_CAPS;

            return $this->result(
                CapResolutionStatus::AMBIGUOUS,
                CapResolutionBasis::CIVIC_RANGE,
                $caps,
                $applicableEntries,
                $unsupportedEntries,
                $diagnostics,
            );
        }

        if ($hasUnsupportedInterpretation || isset($diagnostics[CapResolutionDiagnostic::NON_ORDINARY_CAP->value])) {
            return $this->result(
                CapResolutionStatus::INDETERMINATE,
                CapResolutionBasis::NONE,
                $caps,
                $applicableEntries,
                $unsupportedEntries,
                $diagnostics,
            );
        }

        if ($caps === []) {
            $diagnostics[CapResolutionDiagnostic::NO_APPLICABLE_RANGE->value] = CapResolutionDiagnostic::NO_APPLICABLE_RANGE;

            return $this->result(
                CapResolutionStatus::NO_MATCH,
                CapResolutionBasis::NONE,
                [],
                [],
                [],
                $diagnostics,
            );
        }

        return $this->result(
            CapResolutionStatus::RESOLVED,
            CapResolutionBasis::CIVIC_RANGE,
            $caps,
            $applicableEntries,
            [],
            $diagnostics,
        );
    }

    /**
     * @param list<DirectoryEntry> $entries
     */
    private function resolveWithoutHouseNumber(array $entries): CapResolution
    {
        $diagnostics = [CapResolutionDiagnostic::NO_HOUSE_NUMBER->value => CapResolutionDiagnostic::NO_HOUSE_NUMBER];
        $applicableEntries = [];
        $unsupportedEntries = [];
        $candidateCaps = [];

        foreach ($entries as $entry) {
            $applicableEntries[] = $entry;
            $unsupportedReason = $this->rangeOrParityProblem($entry);
            if ($unsupportedReason !== null) {
                $unsupportedEntries[] = $entry;
                $diagnostics[$unsupportedReason->value] = $unsupportedReason;
                continue;
            }

            if (!$this->isOrdinaryCap($entry->cap)) {
                $unsupportedEntries[] = $entry;
                $diagnostics[CapResolutionDiagnostic::NON_ORDINARY_CAP->value] = CapResolutionDiagnostic::NON_ORDINARY_CAP;
                continue;
            }

            $candidateCaps[$entry->cap] = $entry->cap;
        }

        $caps = $this->sortedCaps($candidateCaps);
        if (count($caps) > 1) {
            $diagnostics[CapResolutionDiagnostic::MULTIPLE_CAPS->value] = CapResolutionDiagnostic::MULTIPLE_CAPS;
        }

        if ($unsupportedEntries !== []) {
            return $this->result(
                CapResolutionStatus::INDETERMINATE,
                CapResolutionBasis::NONE,
                $caps,
                $applicableEntries,
                $unsupportedEntries,
                $diagnostics,
            );
        }

        if (count($caps) > 1) {
            return $this->result(
                CapResolutionStatus::AMBIGUOUS,
                CapResolutionBasis::NONE,
                $caps,
                $applicableEntries,
                [],
                $diagnostics,
            );
        }

        if (count($caps) === 1) {
            return $this->result(
                CapResolutionStatus::RESOLVED,
                CapResolutionBasis::UNIQUE_TERRITORIAL_CAP,
                $caps,
                $applicableEntries,
                [],
                $diagnostics,
            );
        }

        return $this->result(
            CapResolutionStatus::INDETERMINATE,
            CapResolutionBasis::NONE,
            [],
            $applicableEntries,
            [],
            $diagnostics,
        );
    }

    private function rangeOrParityProblem(DirectoryEntry $entry): ?CapResolutionDiagnostic
    {
        if (!in_array($entry->pariDispa, ['T', 'P', 'D'], true)) {
            return CapResolutionDiagnostic::UNSUPPORTED_PARITY;
        }

        if (!$this->isUnsignedInteger($entry->civicoDa) || !$this->isUnsignedInteger($entry->civicoA)) {
            return CapResolutionDiagnostic::NON_NUMERIC_RANGE;
        }

        if ($this->compareUnsignedIntegers($entry->civicoDa, $entry->civicoA) > 0) {
            return CapResolutionDiagnostic::INVALID_NUMERIC_RANGE;
        }

        return null;
    }

    private function matchesRangeAndParity(DirectoryEntry $entry, string $number): bool
    {
        if ($this->compareUnsignedIntegers($entry->civicoDa, $number) > 0
            || $this->compareUnsignedIntegers($number, $entry->civicoA) > 0) {
            return false;
        }

        $isEven = ((int) $number[strlen($number) - 1] % 2) === 0;

        return match ($entry->pariDispa) {
            'T' => true,
            'P' => $isEven,
            'D' => !$isEven,
        };
    }

    private function isUnsignedInteger(string $value): bool
    {
        return preg_match('/\A[0-9]+\z/', $value) === 1;
    }

    private function compareUnsignedIntegers(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';

        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }

        return strcmp($left, $right) <=> 0;
    }

    private function isOrdinaryCap(string $cap): bool
    {
        return preg_match('/\A[0-9]{5}\z/', $cap) === 1;
    }

    /**
     * @param array<string, string> $caps
     * @return list<string>
     */
    private function sortedCaps(array $caps): array
    {
        $values = array_values($caps);
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * @param list<string> $candidateCaps
     * @param list<DirectoryEntry> $applicableEntries
     * @param list<DirectoryEntry> $unsupportedEntries
     * @param array<string, CapResolutionDiagnostic> $diagnostics
     */
    private function result(
        CapResolutionStatus $status,
        CapResolutionBasis $basis,
        array $candidateCaps,
        array $applicableEntries,
        array $unsupportedEntries,
        array $diagnostics,
    ): CapResolution {
        $orderedDiagnostics = [];
        foreach (CapResolutionDiagnostic::cases() as $diagnostic) {
            if (isset($diagnostics[$diagnostic->value])) {
                $orderedDiagnostics[] = $diagnostic;
            }
        }

        return new CapResolution(
            $status,
            $basis,
            $candidateCaps,
            $applicableEntries,
            $unsupportedEntries,
            $orderedDiagnostics,
        );
    }
}
