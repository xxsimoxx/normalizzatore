<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use InvalidArgumentException;
use Normalizzatore\Address\AddressInput;
use Normalizzatore\Address\AddressResolutionStrategy;
use Normalizzatore\Address\AddressStrategyClassifier;
use Normalizzatore\Address\AddressParser;
use Normalizzatore\Directory\AddressDirectoryInterface;

/** Coordinates the existing territorial and street-based resolution components. */
final readonly class AddressResolutionOrchestrator
{
    public function __construct(
        private AddressStrategyClassifier $strategyClassifier,
        private AddressParser $addressParser,
        private AddressDirectoryInterface $directory,
        private CapResolver $capResolver,
        private TerritorialResolver $territorialResolver,
    ) {
    }

    public function resolve(AddressInput $input): AddressResolution
    {
        $strategy = $this->strategyClassifier->classify($input);

        // Source CAP is deliberately excluded: it must not cause an otherwise empty
        // operational address to be looked up or influence the outcome.
        if (trim($input->vianum) === ''
            && trim($input->city ?? '') === ''
            && trim($input->province ?? '') === '') {
            $territorialResolution = $this->territorialResolver->resolve([]);

            return new AddressResolution(
                $strategy,
                AddressResolutionStatus::NO_MATCH,
                [],
                null,
                $strategy === AddressResolutionStrategy::TERRITORIAL ? $territorialResolution : null,
                [],
                [AddressResolutionDiagnostic::EMPTY_ADDRESS_INPUT],
            );
        }

        if ($strategy === AddressResolutionStrategy::TERRITORIAL) {
            $territorialResolution = $this->territorialResolver->resolve(
                $this->directory->findTerritorialEntries($input->city ?? ''),
            );

            return $this->territorialResult($territorialResolution);
        }

        $parsedAddress = $this->addressParser->parse($input);
        $candidateResolutions = [];
        foreach ($parsedAddress->candidates as $candidate) {
            $entries = $this->directory->findByStreetCityProvince(
                $candidate->streetName,
                $input->city ?? '',
                $input->province ?? '',
            );
            $candidateResolutions[] = new StreetCandidateResolution(
                $candidate,
                $entries,
                $this->capResolver->resolve($candidate, $entries),
            );
        }

        return $this->streetResult($candidateResolutions);
    }

    private function territorialResult(TerritorialResolution $resolution): AddressResolution
    {
        $diagnostics = [];
        if ($resolution->status === TerritorialResolutionStatus::NO_MATCH) {
            $diagnostics[] = AddressResolutionDiagnostic::NO_TERRITORIAL_MATCH;
        }
        if (in_array(TerritorialResolutionDiagnostic::MULTIPLE_ORDINARY_CAPS, $resolution->diagnostics, true)) {
            $diagnostics[] = AddressResolutionDiagnostic::MULTIPLE_TERRITORIAL_CAPS;
        }
        if (in_array(TerritorialResolutionDiagnostic::NON_ORDINARY_CAP, $resolution->diagnostics, true)) {
            $diagnostics[] = AddressResolutionDiagnostic::SPECIAL_CAP_PRESENT;
        }
        if ($resolution->status === TerritorialResolutionStatus::INDETERMINATE) {
            $diagnostics[] = AddressResolutionDiagnostic::INDETERMINATE_EVIDENCE;
        }

        return new AddressResolution(
            AddressResolutionStrategy::TERRITORIAL,
            $this->commonStatus($resolution->status),
            $resolution->candidateCaps,
            $resolution->resolvedCap,
            $resolution,
            [],
            $diagnostics,
        );
    }

    /** @param list<StreetCandidateResolution> $candidateResolutions */
    private function streetResult(array $candidateResolutions): AddressResolution
    {
        if (!array_is_list($candidateResolutions)) {
            throw new InvalidArgumentException('Street candidate resolutions must be provided as a list.');
        }
        if ($candidateResolutions === []) {
            return new AddressResolution(
                AddressResolutionStrategy::STREET_BASED,
                AddressResolutionStatus::NO_MATCH,
                [],
                null,
                null,
                [],
                [AddressResolutionDiagnostic::NO_ADDRESS_CANDIDATES],
            );
        }

        $candidateCaps = [];
        $diagnostics = [];
        $resolvedCount = 0;
        $hasAmbiguousResult = false;
        $hasIndeterminateResult = false;
        $hasDirectoryEvidence = false;

        foreach ($candidateResolutions as $candidateResolution) {
            if (!$candidateResolution instanceof StreetCandidateResolution) {
                throw new InvalidArgumentException('Street candidate resolutions must contain StreetCandidateResolution values.');
            }

            $hasDirectoryEvidence = $hasDirectoryEvidence || $candidateResolution->directoryEntries !== [];
            $resolution = $candidateResolution->resolution;
            if ($resolution->status === CapResolutionStatus::RESOLVED) {
                ++$resolvedCount;
            } elseif ($resolution->status === CapResolutionStatus::AMBIGUOUS) {
                $hasAmbiguousResult = true;
            } elseif ($resolution->status === CapResolutionStatus::INDETERMINATE) {
                $hasIndeterminateResult = true;
            }

            foreach ($resolution->candidateCaps as $cap) {
                $candidateCaps[$cap] = $cap;
            }
            if (in_array(CapResolutionDiagnostic::NON_ORDINARY_CAP, $resolution->diagnostics, true)) {
                $diagnostics[AddressResolutionDiagnostic::SPECIAL_CAP_PRESENT->value] = AddressResolutionDiagnostic::SPECIAL_CAP_PRESENT;
            }
        }

        $caps = array_values($candidateCaps);
        sort($caps, SORT_STRING);
        if (!$hasDirectoryEvidence) {
            $diagnostics[AddressResolutionDiagnostic::NO_STREET_MATCH->value] = AddressResolutionDiagnostic::NO_STREET_MATCH;
        }
        if ($hasIndeterminateResult) {
            $diagnostics[AddressResolutionDiagnostic::INDETERMINATE_EVIDENCE->value] = AddressResolutionDiagnostic::INDETERMINATE_EVIDENCE;
        }
        if ($resolvedCount > 1) {
            $diagnostics[AddressResolutionDiagnostic::MULTIPLE_STREET_INTERPRETATIONS->value] = AddressResolutionDiagnostic::MULTIPLE_STREET_INTERPRETATIONS;
        }

        if (count($caps) > 1) {
            $diagnostics[AddressResolutionDiagnostic::MULTIPLE_STREET_CAPS->value] = AddressResolutionDiagnostic::MULTIPLE_STREET_CAPS;
            $status = AddressResolutionStatus::AMBIGUOUS;
            $resolvedCap = null;
        } elseif ($hasAmbiguousResult) {
            // An ambiguous per-candidate result must carry distinct CAP candidates.
            $status = AddressResolutionStatus::AMBIGUOUS;
            $resolvedCap = null;
        } elseif ($hasIndeterminateResult) {
            $status = AddressResolutionStatus::INDETERMINATE;
            $resolvedCap = null;
        } elseif ($caps !== []) {
            $status = AddressResolutionStatus::RESOLVED;
            $resolvedCap = $caps[0];
        } else {
            $status = AddressResolutionStatus::NO_MATCH;
            $resolvedCap = null;
        }

        return new AddressResolution(
            AddressResolutionStrategy::STREET_BASED,
            $status,
            $caps,
            $resolvedCap,
            null,
            $candidateResolutions,
            $this->orderedDiagnostics($diagnostics),
        );
    }

    private function commonStatus(TerritorialResolutionStatus $status): AddressResolutionStatus
    {
        return match ($status) {
            TerritorialResolutionStatus::RESOLVED => AddressResolutionStatus::RESOLVED,
            TerritorialResolutionStatus::NO_MATCH => AddressResolutionStatus::NO_MATCH,
            TerritorialResolutionStatus::AMBIGUOUS => AddressResolutionStatus::AMBIGUOUS,
            TerritorialResolutionStatus::INDETERMINATE => AddressResolutionStatus::INDETERMINATE,
        };
    }

    /** @param array<string, AddressResolutionDiagnostic> $diagnostics
     *  @return list<AddressResolutionDiagnostic>
     */
    private function orderedDiagnostics(array $diagnostics): array
    {
        $ordered = [];
        foreach (AddressResolutionDiagnostic::cases() as $diagnostic) {
            if (isset($diagnostics[$diagnostic->value])) {
                $ordered[] = $diagnostic;
            }
        }

        return $ordered;
    }
}
