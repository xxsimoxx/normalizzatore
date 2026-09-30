<?php

declare(strict_types=1);

namespace Normalizzatore\Normalization;

use Normalizzatore\Address\AddressCandidate;
use Normalizzatore\Address\AddressInput;
use Normalizzatore\Address\AddressParser;
use Normalizzatore\Address\AddressResolutionStrategy;
use Normalizzatore\Address\AddressSyntaxPreference;
use Normalizzatore\Address\AddressSyntaxPreferenceEvaluator;
use Normalizzatore\Directory\DirectoryEntry;
use Normalizzatore\Directory\DirectoryKeyNormalizer;
use Normalizzatore\Directory\TerritorialEntry;
use Normalizzatore\Resolution\AddressResolution;
use Normalizzatore\Resolution\StreetCandidateResolution;

/** Produces conservative field values from an input and its existing resolution evidence. */
final readonly class AddressFieldNormalizer
{
    public function __construct(
        private AddressParser $addressParser = new AddressParser(),
        private DirectoryKeyNormalizer $directoryKeyNormalizer = new DirectoryKeyNormalizer(),
        private AddressSyntaxPreferenceEvaluator $preferenceEvaluator = new AddressSyntaxPreferenceEvaluator(),
    ) {
    }

    public function normalize(AddressInput $input, AddressResolution $resolution): AddressFieldNormalization
    {
        /** @var list<array{candidate: AddressCandidate, entries: list<DirectoryEntry>}> $interpretations */
        $interpretations = [];
        $syntaxPreference = null;
        $parsedAddress = null;
        if ($resolution->strategy === AddressResolutionStrategy::STREET_BASED) {
            foreach ($resolution->streetCandidateResolutions as $candidateResolution) {
                $interpretations[] = [
                    'candidate' => $candidateResolution->candidate,
                    'entries' => $candidateResolution->directoryEntries,
                ];
            }
            $syntaxPreference = $this->preferenceEvaluator->evaluate(
                $input->vianum,
                array_map(static fn (array $item): AddressCandidate => $item['candidate'], $interpretations),
            );
        } else {
            // The territorial orchestrator intentionally does not parse streets. Parse here once
            // to expose syntax only; no directory lookup or CAP evidence is inferred from it.
            $parsedAddress = $this->addressParser->parse($input);
            $syntaxPreference = $parsedAddress->syntaxPreference;
            foreach ($parsedAddress->candidates as $candidate) {
                $interpretations[] = ['candidate' => $candidate, 'entries' => []];
            }
        }

        $supported = [];
        foreach ($interpretations as $item) {
            $relevantEntries = array_values(array_filter(
                $item['entries'],
                fn (DirectoryEntry $entry): bool => $this->sameConservativeKey($entry->citta, $input->city ?? '')
                    && $this->sameConservativeKey($entry->pr, $input->province ?? ''),
            ));
            if ($relevantEntries !== []) {
                $supported[] = ['candidate' => $item['candidate'], 'entries' => $relevantEntries];
            }
        }
        if ($supported !== []) {
            // Directory evidence remains authoritative for field values, independently of
            // the syntax preference. The preference remains available as separate evidence.
            $relevant = $supported;
        } elseif ($syntaxPreference?->preferredCandidate !== null) {
            $relevant = array_values(array_filter(
                $interpretations,
                static fn (array $item): bool => $item['candidate'] === $syntaxPreference->preferredCandidate,
            ));
        } else {
            $relevant = $interpretations;
        }
        $directoryEntries = [];
        foreach ($supported as $item) {
            array_push($directoryEntries, ...$item['entries']);
        }

        $territorialEntries = $resolution->territorialResolution?->evidence ?? [];
        $cityEvidence = $directoryEntries !== []
            ? array_map(static fn (DirectoryEntry $entry): string => $entry->citta, $directoryEntries)
            : array_map(static fn (TerritorialEntry $entry): string => $entry->city, $territorialEntries);
        $provinceEvidence = $directoryEntries !== []
            ? array_map(static fn (DirectoryEntry $entry): string => $entry->pr, $directoryEntries)
            : array_map(static fn (TerritorialEntry $entry): string => $entry->province, $territorialEntries);

        $street = $this->parsedField(
            NormalizedFieldName::STREET,
            array_map(static fn (array $item): string => $item['candidate']->streetName, $relevant),
            $input->vianum,
            $this->uniqueDirectoryValues(array_map(
                static fn (DirectoryEntry $entry): string => $entry->vianum,
                $directoryEntries,
            )),
            true,
            $directoryEntries !== [],
        );
        $number = $this->parsedField(
            NormalizedFieldName::HOUSE_NUMBER,
            array_map(static fn (array $item): ?string => $item['candidate']->houseNumber?->number, $relevant),
            null,
            [],
            false,
            $directoryEntries !== [],
        );
        $details = $this->parsedField(
            NormalizedFieldName::CIVIC_DETAILS,
            array_map(static fn (array $item): string => $item['candidate']->trailingInformation, $relevant),
            null,
            [],
            false,
            $directoryEntries !== [],
        );

        $city = $this->sourceField(
            NormalizedFieldName::CITY,
            $input->city,
            $this->uniqueDirectoryValues($cityEvidence),
            false,
        );
        $province = $this->sourceField(
            NormalizedFieldName::PROVINCE,
            $input->province,
            $this->uniqueDirectoryValues($provinceEvidence),
            true,
        );

        return new AddressFieldNormalization(
            $input->vianum,
            $resolution,
            $street,
            $number,
            $details,
            $city,
            $province,
            $syntaxPreference,
            $parsedAddress,
        );
    }

    /**
     * @param list<?string> $values
     * @param list<string> $directoryValues
     */
    private function parsedField(
        NormalizedFieldName $field,
        array $values,
        ?string $fallbackOriginal,
        array $directoryValues,
        bool $allowDirectoryValue,
        bool $hasDirectoryEvidence,
    ): NormalizedField {
        if ($values === []) {
            return $this->unavailable($field, $fallbackOriginal, [AddressFieldDiagnostic::NO_PARSER_INTERPRETATION]);
        }
        $distinct = $this->distinctValues($values);
        $comparisonValues = $field === NormalizedFieldName::STREET
            ? array_map(fn (?string $value): ?string => $value === null ? null : $this->collapseWhitespace($value), $values)
            : $values;
        if (count($this->distinctValues($comparisonValues)) !== 1) {
            return new NormalizedField(
                $field,
                $fallbackOriginal,
                null,
                NormalizationOrigin::ORIGINAL,
                NormalizedFieldStatus::AMBIGUOUS,
                diagnostics: [AddressFieldDiagnostic::MULTIPLE_PARSER_INTERPRETATIONS],
            );
        }

        $original = $distinct[0];
        if ($original === null || trim($original) === '') {
            return $this->missing($field, $original);
        }

        $syntaxValue = $field === NormalizedFieldName::CIVIC_DETAILS
            || $field === NormalizedFieldName::HOUSE_NUMBER
            ? $original
            : $this->collapseWhitespace($original);

        if ($allowDirectoryValue && count($directoryValues) > 1) {
            $directoryKeys = array_values(array_unique(array_map(
                fn (string $value): string => $this->directoryKeyNormalizer->normalize($value),
                $directoryValues,
            )));
            if (count($directoryKeys) > 1) {
                return $this->valueWithoutDirectoryChoice(
                    $field,
                    $original,
                    $syntaxValue,
                    [AddressFieldDiagnostic::MULTIPLE_DIRECTORY_SPELLINGS],
                    NormalizationOrigin::SYNTAX,
                );
            }
        }
        if ($allowDirectoryValue && count($directoryValues) === 1) {
            $directoryValue = $directoryValues[0];
            if ($this->sameConservativeKey($original, $directoryValue) && $directoryValue !== $original) {
                return $this->corrected($field, $original, $directoryValue, NormalizationOrigin::DIRECTORY);
            }
        }

        $result = $this->syntaxOrOriginal($field, $original, $syntaxValue, [], NormalizationOrigin::SYNTAX);
        if (!$hasDirectoryEvidence) {
            return new NormalizedField(
                $field,
                $result->originalValue,
                $result->normalizedValue,
                $result->origin,
                NormalizedFieldStatus::UNVERIFIABLE,
                $result->correction,
                [...$result->diagnostics, AddressFieldDiagnostic::NO_DIRECTORY_EVIDENCE],
            );
        }

        return $result;
    }

    /** @param list<string> $directoryValues */
    private function sourceField(
        NormalizedFieldName $field,
        ?string $original,
        array $directoryValues,
        bool $province,
    ): NormalizedField {
        if ($original === null || trim($original) === '') {
            $diagnostics = $province ? [AddressFieldDiagnostic::PROVINCE_SOURCE_MISSING] : [];
            if ($directoryValues === []) {
                $diagnostics[] = AddressFieldDiagnostic::NO_DIRECTORY_EVIDENCE;
            }

            return $this->missing($field, $original, $diagnostics);
        }

        $syntaxValue = $this->collapseWhitespace($original);
        $diagnostics = $directoryValues === [] ? [AddressFieldDiagnostic::NO_DIRECTORY_EVIDENCE] : [];

        if ($province) {
            $sourceSigla = trim($original);
            if (preg_match('/\A[A-Za-z]{2}\z/', $sourceSigla) !== 1) {
                $diagnostics[] = AddressFieldDiagnostic::PROVINCE_SOURCE_INVALID;

                return $this->unverifiableSource($field, $original, $syntaxValue, $diagnostics);
            }
            if ($directoryValues === []) {
                return $this->unverifiableSource($field, $original, $syntaxValue, $diagnostics);
            }

            $directorySigle = [];
            $invalidDirectoryProvince = false;
            foreach ($directoryValues as $directoryValue) {
                $value = trim($directoryValue);
                if (preg_match('/\A[A-Za-z]{2}\z/', $value) !== 1) {
                    $diagnostics[] = AddressFieldDiagnostic::INVALID_DIRECTORY_PROVINCE;
                    $invalidDirectoryProvince = true;
                    continue;
                }
                $directorySigle[] = strtoupper($value);
            }
            $directorySigle = array_values(array_unique($directorySigle));
            if ($invalidDirectoryProvince) {
                return $this->unverifiableSource($field, $original, $syntaxValue, $diagnostics);
            }
            if (count($directorySigle) > 1) {
                $diagnostics[] = AddressFieldDiagnostic::MULTIPLE_DIRECTORY_PROVINCES;

                return $this->unverifiableSource($field, $original, $syntaxValue, $diagnostics);
            }
            if (count($directorySigle) === 1 && strtoupper($sourceSigla) !== $directorySigle[0]) {
                $diagnostics[] = AddressFieldDiagnostic::PROVINCE_SIGLA_DIFFERS_FROM_DIRECTORY;

                return $this->unverifiableSource($field, $original, $syntaxValue, $diagnostics);
            }

            return $this->syntaxOrOriginal($field, $original, $syntaxValue, $diagnostics);
        }

        if ($directoryValues === []) {
            return $this->unverifiableSource($field, $original, $syntaxValue, $diagnostics);
        }
        foreach ($directoryValues as $directoryValue) {
            if (!$this->sameConservativeKey($original, $directoryValue)) {
                $diagnostics[] = AddressFieldDiagnostic::CITY_NAME_DIFFERS_FROM_DIRECTORY;

                return $this->unverifiableSource($field, $original, $syntaxValue, $diagnostics);
            }
        }

        return $this->syntaxOrOriginal($field, $original, $syntaxValue, $diagnostics);
    }

    /** @param list<AddressFieldDiagnostic> $diagnostics */
    private function unverifiableSource(
        NormalizedFieldName $field,
        string $original,
        string $syntaxValue,
        array $diagnostics,
    ): NormalizedField {
        $syntax = $this->syntaxOrOriginal($field, $original, $syntaxValue, $diagnostics);

        return new NormalizedField(
            $field,
            $syntax->originalValue,
            $syntax->normalizedValue,
            $syntax->origin,
            NormalizedFieldStatus::UNVERIFIABLE,
            $syntax->correction,
            $syntax->diagnostics,
        );
    }

    /** @param list<AddressFieldDiagnostic> $diagnostics */
    private function syntaxOrOriginal(
        NormalizedFieldName $field,
        string $original,
        string $normalized,
        array $diagnostics = [],
        NormalizationOrigin $unchangedOrigin = NormalizationOrigin::ORIGINAL,
    ): NormalizedField {
        if ($original === $normalized) {
            return new NormalizedField(
                $field,
                $original,
                $normalized,
                $unchangedOrigin,
                NormalizedFieldStatus::CONFIRMED,
                diagnostics: $diagnostics,
            );
        }

        $correction = new FieldCorrection(
            $field,
            $original,
            $normalized,
            FieldCorrectionReason::WHITESPACE_NORMALIZATION,
            NormalizationOrigin::SYNTAX,
        );

        return new NormalizedField(
            $field,
            $original,
            $normalized,
            NormalizationOrigin::SYNTAX,
            NormalizedFieldStatus::SYNTAX_NORMALIZED,
            $correction,
            $diagnostics,
        );
    }

    /** @param list<AddressFieldDiagnostic> $diagnostics */
    private function corrected(
        NormalizedFieldName $field,
        string $original,
        string $proposed,
        NormalizationOrigin $origin,
        array $diagnostics = [],
    ): NormalizedField {
        $reason = $origin === NormalizationOrigin::DIRECTORY
            ? FieldCorrectionReason::DIRECTORY_CANONICAL_VALUE
            : FieldCorrectionReason::WHITESPACE_NORMALIZATION;
        $correction = new FieldCorrection($field, $original, $proposed, $reason, $origin);

        return new NormalizedField(
            $field,
            $original,
            $proposed,
            $origin,
            NormalizedFieldStatus::DIRECTORY_CORRECTION,
            $correction,
            $diagnostics,
        );
    }

    /** @param list<AddressFieldDiagnostic> $diagnostics */
    private function valueWithoutDirectoryChoice(
        NormalizedFieldName $field,
        string $original,
        string $syntaxValue,
        array $diagnostics,
        NormalizationOrigin $unchangedOrigin = NormalizationOrigin::ORIGINAL,
    ): NormalizedField {
        if ($original !== $syntaxValue) {
            $syntax = $this->syntaxOrOriginal($field, $original, $syntaxValue, $diagnostics, $unchangedOrigin);

            return new NormalizedField(
                $field,
                $syntax->originalValue,
                $syntax->normalizedValue,
                $syntax->origin,
                NormalizedFieldStatus::UNVERIFIABLE,
                $syntax->correction,
                $syntax->diagnostics,
            );
        }

        return new NormalizedField(
            $field,
            $original,
            $syntaxValue,
            $unchangedOrigin,
            NormalizedFieldStatus::UNVERIFIABLE,
            diagnostics: $diagnostics,
        );
    }

    /** @param list<AddressFieldDiagnostic> $diagnostics */
    private function unavailable(
        NormalizedFieldName $field,
        ?string $original,
        array $diagnostics,
    ): NormalizedField {
        if ($original === null || trim($original) === '') {
            return $this->missing($field, $original, $diagnostics);
        }

        return new NormalizedField(
            $field,
            $original,
            null,
            NormalizationOrigin::ORIGINAL,
            NormalizedFieldStatus::UNVERIFIABLE,
            diagnostics: $diagnostics,
        );
    }

    /** @param list<AddressFieldDiagnostic> $diagnostics */
    private function missing(
        NormalizedFieldName $field,
        ?string $original,
        array $diagnostics = [],
    ): NormalizedField {
        return new NormalizedField(
            $field,
            $original,
            null,
            NormalizationOrigin::ORIGINAL,
            NormalizedFieldStatus::MISSING,
            diagnostics: $diagnostics,
        );
    }

    /** @param list<?string> $values @return list<?string> */
    private function distinctValues(array $values): array
    {
        $distinct = [];
        foreach ($values as $value) {
            $key = $value === null ? "\0NULL" : "\0VALUE" . $value;
            $distinct[$key] = $value;
        }

        return array_values($distinct);
    }

    /** @param list<string> $values @return list<string> */
    private function uniqueDirectoryValues(array $values): array
    {
        return array_values(array_unique($values, SORT_STRING));
    }

    private function sameConservativeKey(string $left, string $right): bool
    {
        return $this->directoryKeyNormalizer->normalize($left) === $this->directoryKeyNormalizer->normalize($right);
    }

    private function collapseWhitespace(string $value): string
    {
        $collapsed = preg_replace('/[\p{Z}\x09-\x0D\x{0085}]+/u', ' ', $value);
        if ($collapsed === null) {
            return $value;
        }
        $trimmed = preg_replace('/\A\s+|\s+\z/u', '', $collapsed);

        return $trimmed ?? trim($collapsed);
    }
}
