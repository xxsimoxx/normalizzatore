<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use InvalidArgumentException;
use LogicException;
use Normalizzatore\Address\AddressCandidate;
use Normalizzatore\Address\AddressInput;
use Normalizzatore\Address\AddressResolutionStrategy;
use Normalizzatore\Address\AddressStrategyClassifier;
use Normalizzatore\Address\AddressParser;
use Normalizzatore\Address\StreetNameTokenizer;
use Normalizzatore\City\CityCandidate;
use Normalizzatore\City\FuzzyCityResolution;
use Normalizzatore\City\FuzzyCityResolutionStatus;
use Normalizzatore\City\FuzzyCityResolver;
use Normalizzatore\Directory\AddressDirectoryInterface;
use Normalizzatore\Directory\DirectoryEntry;
use Normalizzatore\Directory\DirectoryKeyNormalizer;
use Normalizzatore\Directory\FuzzyCityCandidateProvider;
use Normalizzatore\Directory\FuzzyStreetCandidateProvider;
use Normalizzatore\Directory\FuzzyStreetCandidateSetStatus;
use Normalizzatore\Directory\TerritorialStreetRecoveryProvider;
use Normalizzatore\Frazione\FrazioneCatalog;
use Normalizzatore\Frazione\FrazioneResolution;
use Normalizzatore\Frazione\FrazioneResolutionDiagnostic;
use Normalizzatore\Frazione\FrazioneResolutionStatus;
use Normalizzatore\Frazione\FrazioneResolver;
use Normalizzatore\Frazione\FrazioneStreetEvidence;
use Normalizzatore\Frazione\FrazioneStreetEvidenceStatus;
use Normalizzatore\Frazione\FrazioneTypeGroup;

/** Coordinates the existing territorial and street-based resolution components. */
final readonly class AddressResolutionOrchestrator
{
    public function __construct(
        private AddressStrategyClassifier $strategyClassifier,
        private AddressParser $addressParser,
        private AddressDirectoryInterface $directory,
        private CapResolver $capResolver,
        private TerritorialResolver $territorialResolver,
        private ?FuzzyStreetCandidateProvider $fuzzyCandidateProvider = null,
        private FuzzyStreetMatcher $fuzzyStreetMatcher = new FuzzyStreetMatcher(),
        private StreetNameTokenizer $streetNameTokenizer = new StreetNameTokenizer(),
        private ?FuzzyCityCandidateProvider $fuzzyCityCandidateProvider = null,
        private FuzzyCityResolver $fuzzyCityResolver = new FuzzyCityResolver(),
        private ?TerritorialStreetRecoveryProvider $territorialStreetRecoveryProvider = null,
        private DirectoryKeyNormalizer $directoryKeyNormalizer = new DirectoryKeyNormalizer(),
        private ?FrazioneCatalog $frazioneCatalog = null,
        private FrazioneResolver $frazioneResolver = new FrazioneResolver(),
    ) {
    }

    public function resolve(AddressInput $input, bool $fuzzy = false, bool $frazioni = false): AddressResolution
    {
        if (!$frazioni || trim((string) $input->city) === '' || $this->frazioneCatalog === null) {
            return $this->resolveAddress($input, $fuzzy);
        }

        // Exact/canonical municipalities always outrank a fraction with the same name.
        if ($this->directory->findTerritorialEntries($input->city ?? '') !== []) {
            return $this->resolveAddress($input, $fuzzy);
        }

        $fractionEntries = $this->frazioneCatalog->find($input->city ?? '');
        if ($fractionEntries === []) {
            $resolved = $this->resolveAddress($input, $fuzzy);
            return $this->copyResolution(
                $resolved,
                new FrazioneResolution(FrazioneResolutionStatus::NO_MATCH, $input->city ?? '', typeGroup: FrazioneTypeGroup::NONE),
                $resolved->geographicEvidence,
                null,
            );
        }

        $verified = [];
        $catalogCapConflict = false;
        foreach ($fractionEntries as $entry) {
            if (trim($entry->comune) === '' || trim($entry->provincia) === '') {
                continue;
            }
            $municipalityTerritory = $this->directory->findTerritorialEntries($entry->comune);
            $matchingTerritory = array_values(array_filter($municipalityTerritory, fn ($territorial): bool =>
                $this->directoryKeyNormalizer->normalize($territorial->city) === $this->directoryKeyNormalizer->normalize($entry->comune)
                && $this->directoryKeyNormalizer->normalize($territorial->province) === $this->directoryKeyNormalizer->normalize($entry->provincia)));
            if (trim($entry->cap) !== '' && $matchingTerritory !== []
                && !in_array($entry->cap, array_map(static fn ($territorial): string => $territorial->cap, $matchingTerritory), true)) {
                $catalogCapConflict = true;
            }
            foreach ($matchingTerritory as $territorial) {
                $verified[$this->directoryKeyNormalizer->normalize($territorial->city) . "\0" . $this->directoryKeyNormalizer->normalize($territorial->province)] = [
                    'comune' => $territorial->city,
                    'provincia' => $territorial->province,
                ];
                break;
            }
        }
        $fraction = $this->frazioneResolver->resolve($input->city ?? '', $input->province, $fractionEntries, array_values($verified));
        if ($catalogCapConflict) {
            $fraction = new FrazioneResolution(
                $fraction->status, $fraction->sourceName, $fraction->entries, $fraction->candidateMunicipalities,
                $fraction->comune, $fraction->provincia, $fraction->typeGroup,
                $fraction->diagnostic ?? FrazioneResolutionDiagnostic::CATALOG_CAP_CONFLICT,
                true,
                $fraction->streetEvidence,
                $fraction->incompleteAlternatives,
            );
        }
        if ($fraction->status === FrazioneResolutionStatus::MATCH
            && trim((string) $input->province) !== ''
            && $this->directoryKeyNormalizer->normalize((string) $input->province)
                !== $this->directoryKeyNormalizer->normalize((string) $fraction->provincia)) {
            $streetEvidence = $this->verifyFractionStreet(
                $input->vianum,
                (string) $fraction->comune,
                (string) $fraction->provincia,
            );
            // Street/civic compatibility is useful diagnostic evidence, but by
            // itself it cannot authorize a fraction correction across provinces.
            $fraction = new FrazioneResolution(
                FrazioneResolutionStatus::INDETERMINATE,
                $fraction->sourceName,
                $fraction->entries,
                $fraction->candidateMunicipalities,
                typeGroup: $fraction->typeGroup,
                diagnostic: FrazioneResolutionDiagnostic::SOURCE_PROVINCE_CONFLICT,
                catalogCapConflict: $fraction->catalogCapConflict,
                streetEvidence: $streetEvidence,
            );
        }
        if ($fraction->status !== FrazioneResolutionStatus::MATCH) {
            // A fraction abstention cannot constrain or disambiguate the city
            // matcher. Let fuzzy city resolution run independently; retain the
            // fraction abstention when that path does not resolve the address.
            $fallback = $this->resolveAddress($input, $fuzzy);
            if ($fuzzy
                && $fallback->status === AddressResolutionStatus::RESOLVED
                && $fallback->fuzzyCityResolution?->status === FuzzyCityResolutionStatus::MATCH) {
                return $fallback;
            }

            return $this->withFrazioneEvidence($fallback, $fraction);
        }

        $effective = new AddressInput($input->vianum, $input->cap, $fraction->comune ?? '', $fraction->provincia ?? '');
        $resolved = $this->resolveAddress($effective, $fuzzy, true);
        if ($resolved->status === AddressResolutionStatus::RESOLVED) {
            $resolved = $this->copyResolution(
                $resolved,
                $fraction,
                new AddressGeographicEvidence(
                    AddressGeographicEvidenceKind::FRAZIONE_TO_COMUNE,
                    $input->city ?? '',
                    $input->province ?? '',
                    $fraction->comune ?? '',
                    $fraction->provincia ?? '',
                ),
                AddressResolutionDiagnostic::FRAZIONE_RECOGNIZED,
            );
        } else {
            $resolved = $this->withFrazioneEvidence($resolved, $fraction);
        }

        return $resolved;
    }

    private function resolveAddress(AddressInput $input, bool $fuzzy, bool $suppressFuzzyCity = false): AddressResolution
    {
        $initialStrategy = $this->strategyClassifier->classify($input);
        // Source CAP is deliberately excluded: it must not cause an otherwise empty
        // operational address to be looked up or influence the outcome.
        if (trim($input->vianum) === ''
            && trim($input->city ?? '') === ''
            && trim($input->province ?? '') === '') {
            $territorialResolution = $this->territorialResolver->resolve([]);

            return new AddressResolution(
                $initialStrategy,
                AddressResolutionStatus::NO_MATCH,
                [],
                null,
                $initialStrategy === AddressResolutionStrategy::TERRITORIAL ? $territorialResolution : null,
                [],
                [AddressResolutionDiagnostic::EMPTY_ADDRESS_INPUT],
            );
        }

        $effectiveInput = $input;
        $fuzzyCityResolution = null;
        $geographicEvidence = null;
        $sourceCity = trim($input->city ?? '');
        if ($fuzzy && !$suppressFuzzyCity && $sourceCity !== ''
            && $initialStrategy !== AddressResolutionStrategy::STREET_BASED
            && $this->fuzzyCityCandidateProvider !== null
            && $this->directory->findTerritorialEntries($sourceCity) === []) {
            $cityCandidates = $this->fuzzyCityCandidateProvider->findCityCandidates();
            $fuzzyCityResolution = $this->fuzzyCityResolver->resolve($sourceCity, $input->province, $cityCandidates);
            if ($fuzzyCityResolution->status === FuzzyCityResolutionStatus::MATCH) {
                $match = $fuzzyCityResolution->match;
                if ($match === null) {
                    throw new LogicException('A fuzzy city MATCH must contain evidence.');
                }
                $effectiveInput = new AddressInput($input->vianum, $input->cap, $match->candidate->name, $match->candidate->province);
                $geographicEvidence = new AddressGeographicEvidence(
                    AddressGeographicEvidenceKind::FUZZY_CITY_CORRECTION,
                    $input->city ?? '',
                    $input->province ?? '',
                    $match->candidate->name,
                    $match->candidate->province,
                    $match,
                );
            }
        }

        $strategy = $this->strategyClassifier->classify($effectiveInput);

        if ($strategy === AddressResolutionStrategy::TERRITORIAL) {
            $territorialResolution = $this->territorialResolver->resolve(
                $this->directory->findTerritorialEntries($effectiveInput->city ?? ''),
            );

            $provinces = $this->uniqueNormalizedValues(array_map(static fn ($entry): string => $entry->province, $territorialResolution->evidence));
            $sourceProvince = trim($effectiveInput->province ?? '');
            $provinceConflict = count($provinces) === 1 && $sourceProvince !== ''
                && $this->directoryKeyNormalizer->normalize($sourceProvince) !== $provinces[0];
            $sourceCap = trim($input->cap ?? '');
            $sourceCapConflicts = preg_match('/\A[0-9]{5}\z/', $sourceCap) === 1
                && !in_array($sourceCap, $territorialResolution->candidateCaps, true);
            if ($provinceConflict && $sourceCapConflicts && trim($effectiveInput->vianum) !== '') {
                if ($fuzzy) {
                    $recovered = $this->recoverTerritorialStreet($input, $effectiveInput, $territorialResolution, $fuzzyCityResolution);
                    if ($recovered !== null) {
                        return $recovered;
                    }
                }

                $territorialResolution = new TerritorialResolution(
                    TerritorialResolutionStatus::INDETERMINATE,
                    $territorialResolution->candidateCaps,
                    null,
                    $territorialResolution->evidence,
                    [...$territorialResolution->diagnostics],
                );

                return $this->territorialResult(
                    $territorialResolution,
                    $fuzzyCityResolution,
                    null,
                    [AddressResolutionDiagnostic::TERRITORIAL_LOCATION_CONFLICT],
                );
            }

            if (count($provinces) === 1 && ($sourceProvince === '' || $provinceConflict) && $territorialResolution->status === TerritorialResolutionStatus::RESOLVED) {
                $resolvedCity = $this->uniqueDisplayValue(array_map(static fn ($entry): string => $entry->city, $territorialResolution->evidence))
                    ?? ($effectiveInput->city ?? '');
                if ($geographicEvidence === null) {
                    $geographicEvidence = new AddressGeographicEvidence(
                        $sourceProvince === '' ? AddressGeographicEvidenceKind::PROVINCE_COMPLETION : AddressGeographicEvidenceKind::PROVINCE_CORRECTION,
                        $input->city ?? '',
                        $input->province ?? '',
                        $resolvedCity,
                        $provinces[0],
                    );
                }
            }

            return $this->territorialResult($territorialResolution, $fuzzyCityResolution, $geographicEvidence);
        }

        $parsedAddress = $this->addressParser->parse($effectiveInput);
        $candidateResolutions = $this->resolveStreetCandidates($parsedAddress->candidates, $effectiveInput);

        if ($this->hasDirectoryEvidence($candidateResolutions)) {
            return $this->streetResult($candidateResolutions, fuzzyCityResolution: $fuzzyCityResolution, geographicEvidence: $geographicEvidence);
        }

        if ($parsedAddress->candidates === []) {
            return $this->streetResult($candidateResolutions, fuzzyCityResolution: $fuzzyCityResolution, geographicEvidence: $geographicEvidence);
        }

        $territorialEntries = $this->directory->findTerritorialEntries($effectiveInput->city ?? '');
        $provinceValues = $this->uniqueNormalizedValues(array_map(
            static fn ($entry): string => $entry->province,
            $territorialEntries,
        ));
        $lookupInput = $effectiveInput;
        $pendingProvinceEvidence = null;
        if (count($provinceValues) === 1) {
            $expectedProvince = $provinceValues[0];
            $sourceProvinceKey = $this->directoryKeyNormalizer->normalize($effectiveInput->province ?? '');
            if ($sourceProvinceKey !== $expectedProvince) {
                $displayCity = $this->uniqueDisplayValue(array_map(
                    static fn ($entry): string => $entry->city,
                    $territorialEntries,
                )) ?? ($effectiveInput->city ?? '');
                $lookupInput = new AddressInput($effectiveInput->vianum, $effectiveInput->cap, $displayCity, $expectedProvince);
                $pendingProvinceEvidence = new AddressGeographicEvidence(
                    $sourceProvinceKey === '' ? AddressGeographicEvidenceKind::PROVINCE_COMPLETION : AddressGeographicEvidenceKind::PROVINCE_CORRECTION,
                    $input->city ?? '',
                    $input->province ?? '',
                    $displayCity,
                    $expectedProvince,
                );
                if ($fuzzyCityResolution?->match !== null) {
                    $pendingProvinceEvidence = new AddressGeographicEvidence(
                        AddressGeographicEvidenceKind::FUZZY_CITY_CORRECTION,
                        $input->city ?? '',
                        $input->province ?? '',
                        $displayCity,
                        $expectedProvince,
                        $fuzzyCityResolution->match,
                    );
                }
                $retried = $this->resolveStreetCandidates($parsedAddress->candidates, $lookupInput);
                if ($this->hasDirectoryEvidence($retried)) {
                    return $this->streetResult(
                        $retried,
                        fuzzyCityResolution: $fuzzyCityResolution,
                        geographicEvidence: $geographicEvidence ?? $pendingProvinceEvidence,
                    );
                }
                $candidateResolutions = $retried;
            }
        }

        if (!$fuzzy) {
            return $this->streetResult($candidateResolutions, fuzzyCityResolution: $fuzzyCityResolution);
        }

        $selected = $this->selectFuzzyParserCandidate($parsedAddress->candidates, $parsedAddress->syntaxPreference?->preferredCandidate);
        if ($selected === null) {
            return $this->streetResult($candidateResolutions, new FuzzyStreetAddressEvidence(
                AddressResolutionDiagnostic::FUZZY_NOT_APPLICABLE,
            ), $fuzzyCityResolution, $geographicEvidence);
        }

        $sourceStreet = $this->streetNameTokenizer->tokenize($selected->streetName);
        if ($sourceStreet === null) {
            return $this->streetResult($candidateResolutions, new FuzzyStreetAddressEvidence(
                AddressResolutionDiagnostic::FUZZY_NOT_APPLICABLE,
                parserCandidate: $selected,
            ), $fuzzyCityResolution, $geographicEvidence);
        }
        if ($this->fuzzyCandidateProvider === null) {
            throw new LogicException('Fuzzy street candidate provider is not configured.');
        }

        $candidateSet = $this->fuzzyCandidateProvider->findCandidates(new FuzzyStreetCandidateQuery(
            $lookupInput->city ?? '',
            $lookupInput->province ?? '',
            $sourceStreet,
        ));
        if ($candidateSet->status !== FuzzyStreetCandidateSetStatus::AVAILABLE) {
            $diagnostic = match ($candidateSet->status) {
                FuzzyStreetCandidateSetStatus::NO_LOCALITY => AddressResolutionDiagnostic::FUZZY_NO_LOCALITY,
                FuzzyStreetCandidateSetStatus::PROVINCE_CONFLICT => AddressResolutionDiagnostic::FUZZY_PROVINCE_CONFLICT,
                FuzzyStreetCandidateSetStatus::AMBIGUOUS_LOCALITY => AddressResolutionDiagnostic::FUZZY_AMBIGUOUS_LOCALITY,
                FuzzyStreetCandidateSetStatus::AVAILABLE => throw new LogicException('Unreachable fuzzy candidate-set status.'),
            };

            return $this->streetResult($candidateResolutions, new FuzzyStreetAddressEvidence(
                $diagnostic,
                $selected,
                $candidateSet,
            ), $fuzzyCityResolution, $geographicEvidence);
        }

        $fuzzyResolution = $this->fuzzyStreetMatcher->match($sourceStreet, $candidateSet->candidates);
        if ($fuzzyResolution->status !== FuzzyStreetResolutionStatus::MATCH) {
            $diagnostic = match ($fuzzyResolution->status) {
                FuzzyStreetResolutionStatus::AMBIGUOUS => AddressResolutionDiagnostic::FUZZY_AMBIGUOUS,
                FuzzyStreetResolutionStatus::NO_MATCH => AddressResolutionDiagnostic::FUZZY_NO_MATCH,
                FuzzyStreetResolutionStatus::NOT_APPLICABLE => AddressResolutionDiagnostic::FUZZY_NOT_APPLICABLE,
                FuzzyStreetResolutionStatus::MATCH => throw new LogicException('Unreachable fuzzy match status.'),
            };

            return $this->streetResult($candidateResolutions, new FuzzyStreetAddressEvidence(
                $diagnostic,
                $selected,
                $candidateSet,
                $fuzzyResolution,
            ), $fuzzyCityResolution, $geographicEvidence);
        }

        $matchedCandidate = $fuzzyResolution->match?->candidate;
        if ($matchedCandidate === null) {
            throw new LogicException('A fuzzy MATCH must carry its selected street name.');
        }
        $entries = $this->fuzzyCandidateProvider->findEntries($candidateSet, new FuzzyStreetNameCandidate($matchedCandidate));
        if ($entries === []) {
            throw new LogicException('A selected fuzzy street name has no directory entries.');
        }

        $matchedResolution = new StreetCandidateResolution($selected, $entries, $this->capResolver->resolve($selected, $entries));
        foreach ($candidateResolutions as $index => $candidateResolution) {
            if ($this->sameCandidate($candidateResolution->candidate, $selected)) {
                $candidateResolutions[$index] = $matchedResolution;
                break;
            }
        }
        $diagnostic = $fuzzyResolution->match->kind === FuzzyStreetMatchKind::ABBREVIATION
            ? AddressResolutionDiagnostic::FUZZY_ABBREVIATION_MATCH
            : AddressResolutionDiagnostic::FUZZY_TYPO_MATCH;

        return $this->streetResult($candidateResolutions, new FuzzyStreetAddressEvidence(
            $diagnostic,
            $selected,
            $candidateSet,
            $fuzzyResolution,
        ), $fuzzyCityResolution, $geographicEvidence ?? $pendingProvinceEvidence);
    }

    /** @param list<StreetCandidateResolution> $candidateResolutions */
    private function hasDirectoryEvidence(array $candidateResolutions): bool
    {
        foreach ($candidateResolutions as $candidateResolution) {
            if ($candidateResolution->directoryEntries !== []) {
                return true;
            }
        }

        return false;
    }

    /** @param list<AddressCandidate> $candidates */
    private function selectFuzzyParserCandidate(array $candidates, ?AddressCandidate $preferred): ?AddressCandidate
    {
        if (count($candidates) === 1) {
            return $candidates[0];
        }
        if ($preferred === null) {
            return null;
        }

        $matches = array_values(array_filter($candidates, fn ($candidate): bool => $this->sameCandidate($candidate, $preferred)));

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function sameCandidate(AddressCandidate $left, AddressCandidate $right): bool
    {
        return $left->streetName === $right->streetName
            && $left->houseNumber?->number === $right->houseNumber?->number
            && $left->trailingInformation === $right->trailingInformation;
    }

    private function territorialResult(
        TerritorialResolution $resolution,
        ?FuzzyCityResolution $fuzzyCityResolution = null,
        ?AddressGeographicEvidence $geographicEvidence = null,
        array $extraDiagnostics = [],
    ): AddressResolution
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
        $cityDiagnostic = $this->fuzzyCityDiagnostic($fuzzyCityResolution);
        if ($cityDiagnostic !== null) {
            $diagnostics[] = $cityDiagnostic;
        }
        array_push($diagnostics, ...$extraDiagnostics);
        $diagnostics = array_values(array_unique($diagnostics, SORT_REGULAR));

        return new AddressResolution(
            AddressResolutionStrategy::TERRITORIAL,
            $this->commonStatus($resolution->status),
            $resolution->candidateCaps,
            $resolution->resolvedCap,
            $resolution,
            [],
            $diagnostics,
            null,
            $fuzzyCityResolution,
            $geographicEvidence,
        );
    }

    /** @param list<StreetCandidateResolution> $candidateResolutions */
    private function streetResult(
        array $candidateResolutions,
        ?FuzzyStreetAddressEvidence $fuzzyEvidence = null,
        ?FuzzyCityResolution $fuzzyCityResolution = null,
        ?AddressGeographicEvidence $geographicEvidence = null,
    ): AddressResolution
    {
        if (!array_is_list($candidateResolutions)) {
            throw new InvalidArgumentException('Street candidate resolutions must be provided as a list.');
        }
        if ($candidateResolutions === []) {
            $diagnostics = [AddressResolutionDiagnostic::NO_ADDRESS_CANDIDATES];
            if ($fuzzyEvidence !== null) {
                $diagnostics[] = $fuzzyEvidence->diagnostic;
            }

            return new AddressResolution(
                AddressResolutionStrategy::STREET_BASED,
                AddressResolutionStatus::NO_MATCH,
                [],
                null,
                null,
                [],
                $diagnostics,
                $fuzzyEvidence,
                $fuzzyCityResolution,
                $geographicEvidence,
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

        if ($fuzzyEvidence !== null) {
            $diagnostics[$fuzzyEvidence->diagnostic->value] = $fuzzyEvidence->diagnostic;
        }
        if ($geographicEvidence?->kind === AddressGeographicEvidenceKind::TERRITORIAL_STREET_RECOVERY) {
            $diagnostics[AddressResolutionDiagnostic::TERRITORIAL_STREET_RECOVERY->value] = AddressResolutionDiagnostic::TERRITORIAL_STREET_RECOVERY;
        }
        $cityDiagnostic = $this->fuzzyCityDiagnostic($fuzzyCityResolution);
        if ($cityDiagnostic !== null) {
            $diagnostics[$cityDiagnostic->value] = $cityDiagnostic;
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
            $fuzzyEvidence,
            $fuzzyCityResolution,
            $geographicEvidence,
        );
    }

    /** @param list<AddressCandidate> $candidates @return list<StreetCandidateResolution> */
    private function resolveStreetCandidates(array $candidates, AddressInput $input): array
    {
        $resolutions = [];
        foreach ($candidates as $candidate) {
            $entries = $this->directory->findByStreetCityProvince($candidate->streetName, $input->city ?? '', $input->province ?? '');
            $resolutions[] = new StreetCandidateResolution($candidate, $entries, $this->capResolver->resolve($candidate, $entries));
        }

        return $resolutions;
    }

    /** @param list<string> $values @return list<string> */
    private function uniqueNormalizedValues(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            $key = $this->directoryKeyNormalizer->normalize($value);
            if ($key !== '') {
                $normalized[$key] = $key;
            }
        }
        $result = array_values($normalized);
        sort($result, SORT_STRING);

        return $result;
    }

    /** @param list<string> $values */
    private function uniqueDisplayValue(array $values): ?string
    {
        $byKey = [];
        foreach ($values as $value) {
            $byKey[$this->directoryKeyNormalizer->normalize($value)][] = $value;
        }
        if (count($byKey) !== 1) {
            return null;
        }
        $variants = array_values($byKey)[0];
        sort($variants, SORT_STRING);

        return $variants[0] ?? null;
    }

    private function fuzzyCityDiagnostic(?FuzzyCityResolution $resolution): ?AddressResolutionDiagnostic
    {
        if ($resolution === null) {
            return null;
        }

        return match ($resolution->status) {
            FuzzyCityResolutionStatus::MATCH => AddressResolutionDiagnostic::FUZZY_CITY_MATCH,
            FuzzyCityResolutionStatus::AMBIGUOUS => AddressResolutionDiagnostic::FUZZY_CITY_AMBIGUOUS,
            FuzzyCityResolutionStatus::NO_MATCH => AddressResolutionDiagnostic::FUZZY_CITY_NO_MATCH,
            FuzzyCityResolutionStatus::NOT_APPLICABLE => AddressResolutionDiagnostic::FUZZY_CITY_NOT_APPLICABLE,
        };
    }

    private function recoverTerritorialStreet(
        AddressInput $sourceInput,
        AddressInput $effectiveInput,
        TerritorialResolution $territorialResolution,
        ?FuzzyCityResolution $fuzzyCityResolution,
    ): ?AddressResolution {
        if ($this->territorialStreetRecoveryProvider === null) {
            return null;
        }

        $parsed = $this->addressParser->parse($effectiveInput);
        $candidate = $this->selectFuzzyParserCandidate($parsed->candidates, $parsed->syntaxPreference?->preferredCandidate);
        if ($candidate === null) {
            return null;
        }
        $territorialProvinces = $this->uniqueNormalizedValues(array_map(
            static fn ($entry): string => $entry->province,
            $territorialResolution->evidence,
        ));
        $territorialCity = $this->uniqueDisplayValue(array_map(
            static fn ($entry): string => $entry->city,
            $territorialResolution->evidence,
        ));
        $entries = [];
        if (count($territorialProvinces) === 1 && $territorialCity !== null) {
            // An exact source city plus its independently established province is
            // stronger than either source province or source CAP on its own.
            $entries = $this->findRecoveryEntriesInLocality(
                $candidate->streetName,
                $territorialCity,
                $territorialProvinces[0],
            );
            if ($entries !== []) {
                $localResolution = $this->capResolver->resolve($candidate, $entries);
                if ($localResolution->status !== CapResolutionStatus::RESOLVED) {
                    return null;
                }

                return $this->buildTerritorialStreetRecovery(
                    $sourceInput,
                    $candidate,
                    $entries,
                    $localResolution,
                    [$territorialCity . ' / ' . $territorialProvinces[0]],
                    $fuzzyCityResolution,
                );
            }
        }

        // If the street is absent from the corrected source locality, the explicit
        // province can bound a diagnostic recovery search. The source CAP is not
        // available to candidate generation, scoring or tie breaking.
        $entries = $this->territorialStreetRecoveryProvider->findByStreetAcrossLocalities(
            $candidate->streetName,
            $sourceInput->province,
        );
        if ($entries === []) {
            return null;
        }

        /** @var array<string, list<DirectoryEntry>> $byLocality */
        $byLocality = [];
        $displayLocalities = [];
        foreach ($entries as $entry) {
            $cityKey = $this->directoryKeyNormalizer->normalize($entry->citta);
            $provinceKey = $this->directoryKeyNormalizer->normalize($entry->pr);
            $key = $cityKey . "\0" . $provinceKey;
            $byLocality[$key][] = $entry;
            $displayLocalities[$key] = $entry->citta . ' / ' . $entry->pr;
        }
        ksort($byLocality, SORT_STRING);

        $plausible = [];
        $considered = [];
        foreach ($byLocality as $key => $localityEntries) {
            $resolution = $this->capResolver->resolve($candidate, $localityEntries);
            if (in_array($resolution->status, [CapResolutionStatus::RESOLVED, CapResolutionStatus::AMBIGUOUS, CapResolutionStatus::INDETERMINATE], true)) {
                $plausible[] = [$key, $localityEntries, $resolution];
                $considered[] = $displayLocalities[$key];
            }
        }
        if (count($plausible) !== 1 || $plausible[0][2]->status !== CapResolutionStatus::RESOLVED) {
            return null;
        }

        [, $matchedEntries, $capResolution] = $plausible[0];
        return $this->buildTerritorialStreetRecovery(
            $sourceInput,
            $candidate,
            $matchedEntries,
            $capResolution,
            $considered,
            $fuzzyCityResolution,
        );
    }

    /**
     * Try the exact street key first, then formatting variants that differ only
     * by optional whitespace immediately after an apostrophe. This handles legacy
     * directory spellings such as "S' ISCALA" without making them street-fuzzy
     * candidates or allowing another locality to win over an existing local name.
     *
     * @return list<DirectoryEntry>
     */
    private function findRecoveryEntriesInLocality(string $street, string $city, string $province): array
    {
        $variants = [$street];
        $variants[] = preg_replace("/(['’])\\s+/u", '$1', $street) ?? $street;
        $variants[] = preg_replace("/(['’])(?=\\S)/u", '$1 ', $street) ?? $street;
        $seen = [];
        foreach ($variants as $variant) {
            $key = $this->directoryKeyNormalizer->normalize($variant);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $entries = $this->directory->findByStreetCityProvince($variant, $city, $province);
            if ($entries !== []) {
                return $entries;
            }
        }

        return [];
    }

    /** @param list<DirectoryEntry> $matchedEntries @param list<string> $considered */
    private function buildTerritorialStreetRecovery(
        AddressInput $sourceInput,
        AddressCandidate $candidate,
        array $matchedEntries,
        CapResolution $capResolution,
        array $considered,
        ?FuzzyCityResolution $fuzzyCityResolution,
    ): ?AddressResolution {
        $matchedCity = $this->uniqueDisplayValue(array_map(static fn (DirectoryEntry $entry): string => $entry->citta, $matchedEntries));
        $matchedProvince = $this->uniqueDisplayValue(array_map(static fn (DirectoryEntry $entry): string => $entry->pr, $matchedEntries));
        if ($matchedCity === null || $matchedProvince === null) {
            return null;
        }
        $recovery = new TerritorialStreetRecoveryEvidence(
            $sourceInput->city ?? '',
            $sourceInput->province ?? '',
            $matchedCity,
            $matchedProvince,
            $this->uniqueDisplayValue(array_map(static fn (DirectoryEntry $entry): string => $entry->vianum, $matchedEntries)) ?? $candidate->streetName,
            $considered,
        );
        $geographicEvidence = new AddressGeographicEvidence(
            AddressGeographicEvidenceKind::TERRITORIAL_STREET_RECOVERY,
            $sourceInput->city ?? '',
            $sourceInput->province ?? '',
            $matchedCity,
            $matchedProvince,
            streetRecovery: $recovery,
        );
        $streetResolution = new StreetCandidateResolution($candidate, $matchedEntries, $capResolution);

        return $this->streetResult(
            [$streetResolution],
            fuzzyCityResolution: $fuzzyCityResolution,
            geographicEvidence: $geographicEvidence,
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

    private function withFrazioneEvidence(AddressResolution $resolution, FrazioneResolution $frazione): AddressResolution
    {
        $diagnostic = match ($frazione->status) {
            FrazioneResolutionStatus::MATCH => AddressResolutionDiagnostic::FRAZIONE_RECOGNIZED,
            FrazioneResolutionStatus::AMBIGUOUS => AddressResolutionDiagnostic::FRAZIONE_AMBIGUOUS,
            FrazioneResolutionStatus::INDETERMINATE => AddressResolutionDiagnostic::FRAZIONE_UNVERIFIED,
            FrazioneResolutionStatus::NO_MATCH, FrazioneResolutionStatus::NOT_APPLICABLE => null,
        };
        return $this->copyResolution($resolution, $frazione, $resolution->geographicEvidence, $diagnostic);
    }

    private function verifyFractionStreet(string $sourceAddress, string $city, string $province): FrazioneStreetEvidence
    {
        $parsed = $this->addressParser->parse(new AddressInput($sourceAddress, '', $city, $province));
        $candidate = $parsed->syntaxPreference?->preferredCandidate;
        if ($candidate === null && count($parsed->candidates) === 1) {
            $candidate = $parsed->candidates[0];
        }
        if ($candidate === null) {
            return new FrazioneStreetEvidence(FrazioneStreetEvidenceStatus::PARSER_AMBIGUOUS, null, null, 0);
        }

        $entries = $this->directory->findByStreetCityProvince($candidate->streetName, $city, $province);
        if ($entries === []) {
            return new FrazioneStreetEvidence(
                FrazioneStreetEvidenceStatus::STREET_NOT_FOUND,
                $candidate->streetName,
                $candidate->houseNumber?->number,
                0,
            );
        }
        $caps = array_values(array_unique(array_map(static fn (DirectoryEntry $entry): string => $entry->cap, $entries)));
        sort($caps, SORT_STRING);
        if ($candidate->houseNumber === null) {
            return new FrazioneStreetEvidence(
                FrazioneStreetEvidenceStatus::EXACT_STREET,
                $candidate->streetName,
                null,
                count($entries),
                directoryCaps: $caps,
            );
        }

        $civicResolution = $this->capResolver->resolve($candidate, $entries);
        $status = match ($civicResolution->status) {
            CapResolutionStatus::RESOLVED => FrazioneStreetEvidenceStatus::CIVIC_COMPATIBLE,
            CapResolutionStatus::NO_MATCH => FrazioneStreetEvidenceStatus::CIVIC_NOT_COMPATIBLE,
            CapResolutionStatus::AMBIGUOUS, CapResolutionStatus::INDETERMINATE => FrazioneStreetEvidenceStatus::CIVIC_INDETERMINATE,
        };

        return new FrazioneStreetEvidence(
            $status,
            $candidate->streetName,
            $candidate->houseNumber->number,
            count($entries),
            $civicResolution->status,
            $caps,
        );
    }

    private function copyResolution(
        AddressResolution $resolution,
        FrazioneResolution $frazione,
        ?AddressGeographicEvidence $geographicEvidence,
        ?AddressResolutionDiagnostic $diagnostic,
    ): AddressResolution {
        $diagnostics = [];
        foreach ($resolution->diagnostics as $item) {
            $diagnostics[$item->value] = $item;
        }
        if ($diagnostic !== null) {
            $diagnostics[$diagnostic->value] = $diagnostic;
        }

        return new AddressResolution(
            $resolution->strategy,
            $resolution->status,
            $resolution->candidateCaps,
            $resolution->resolvedCap,
            $resolution->territorialResolution,
            $resolution->streetCandidateResolutions,
            $this->orderedDiagnostics($diagnostics),
            $resolution->fuzzyStreetEvidence,
                $resolution->fuzzyCityResolution,
                $geographicEvidence,
                $frazione,
        );
    }
}
