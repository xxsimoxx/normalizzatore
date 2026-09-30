<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use InvalidArgumentException;
use Normalizzatore\Address\AddressResolutionStrategy;

/** Common, typed result of either address-resolution path. */
final readonly class AddressResolution
{
    /**
     * @param list<string> $candidateCaps
     * @param list<StreetCandidateResolution> $streetCandidateResolutions
     * @param list<AddressResolutionDiagnostic> $diagnostics
     */
    public function __construct(
        public AddressResolutionStrategy $strategy,
        public AddressResolutionStatus $status,
        public array $candidateCaps,
        public ?string $resolvedCap,
        public ?TerritorialResolution $territorialResolution,
        public array $streetCandidateResolutions,
        public array $diagnostics,
    ) {
        foreach ([$candidateCaps, $streetCandidateResolutions, $diagnostics] as $list) {
            if (!array_is_list($list)) {
                throw new InvalidArgumentException('Address resolution collections must be lists.');
            }
        }

        $sortedCaps = $candidateCaps;
        sort($sortedCaps, SORT_STRING);
        if ($candidateCaps !== $sortedCaps || count(array_unique($candidateCaps)) !== count($candidateCaps)) {
            throw new InvalidArgumentException('Address candidate CAPs must be unique and sorted lexicographically.');
        }
        foreach ($candidateCaps as $cap) {
            if (preg_match('/\A[0-9]{5}\z/', $cap) !== 1) {
                throw new InvalidArgumentException('Address candidate CAPs must contain exactly five ASCII digits.');
            }
        }
        foreach ($streetCandidateResolutions as $candidateResolution) {
            if (!$candidateResolution instanceof StreetCandidateResolution) {
                throw new InvalidArgumentException('Street resolution evidence must contain StreetCandidateResolution values.');
            }
        }
        foreach ($diagnostics as $diagnostic) {
            if (!$diagnostic instanceof AddressResolutionDiagnostic) {
                throw new InvalidArgumentException('Address resolution diagnostics must use AddressResolutionDiagnostic values.');
            }
        }
        if (count(array_unique(array_map(static fn (AddressResolutionDiagnostic $item): string => $item->value, $diagnostics))) !== count($diagnostics)) {
            throw new InvalidArgumentException('Address resolution diagnostics must be unique.');
        }

        if ($status === AddressResolutionStatus::RESOLVED
            && (count($candidateCaps) !== 1 || $resolvedCap !== $candidateCaps[0])) {
            throw new InvalidArgumentException('A resolved address must expose exactly one resolved CAP.');
        }
        if ($status !== AddressResolutionStatus::RESOLVED && $resolvedCap !== null) {
            throw new InvalidArgumentException('Only a resolved address may expose a resolved CAP.');
        }
        if ($status === AddressResolutionStatus::AMBIGUOUS && count($candidateCaps) < 2) {
            throw new InvalidArgumentException('An ambiguous address must expose multiple candidate CAPs.');
        }
        if ($status === AddressResolutionStatus::NO_MATCH && $candidateCaps !== []) {
            throw new InvalidArgumentException('A no-match address cannot expose candidate CAPs.');
        }

        if ($strategy === AddressResolutionStrategy::TERRITORIAL
            && ($territorialResolution === null || $streetCandidateResolutions !== [])) {
            throw new InvalidArgumentException('A territorial address result must preserve only territorial resolution evidence.');
        }
        if ($strategy === AddressResolutionStrategy::STREET_BASED && $territorialResolution !== null) {
            throw new InvalidArgumentException('A street-based address result cannot contain territorial resolution evidence.');
        }
    }

    public function isResolved(): bool
    {
        return $this->status === AddressResolutionStatus::RESOLVED;
    }
}
