<?php

declare(strict_types=1);

namespace Normalizzatore\Address;

/** Applies a small, deterministic set of syntactic rules to existing parser candidates. */
final class AddressSyntaxPreferenceEvaluator
{
    /** @param list<AddressCandidate> $candidates */
    public function evaluate(string $source, array $candidates): AddressSyntaxPreference
    {
        if ($candidates === []) {
            return new AddressSyntaxPreference(null, AddressSyntaxPreferenceReason::NO_CANDIDATES);
        }
        if (preg_match('/(?:^|\\s)SNC\\s*$/iu', trim($source)) === 1) {
            return new AddressSyntaxPreference(null, AddressSyntaxPreferenceReason::EXPLICIT_SNC);
        }

        $signatures = array_map(static fn (AddressCandidate $candidate): array => [
            self::collapseWhitespace($candidate->streetName),
            $candidate->houseNumber?->number,
            self::collapseWhitespace($candidate->trailingInformation),
        ], $candidates);
        if (count($candidates) > 1 && count(array_unique(array_map('serialize', $signatures))) === 1) {
            return new AddressSyntaxPreference(null, AddressSyntaxPreferenceReason::EQUIVALENT_CANDIDATES);
        }

        $numericCandidates = array_values(array_filter(
            $candidates,
            static fn (AddressCandidate $candidate): bool => $candidate->houseNumber !== null,
        ));
        if ($numericCandidates === []) {
            return new AddressSyntaxPreference(null, AddressSyntaxPreferenceReason::NO_CIVIC_NUMBER);
        }

        $normalized = (new AddressSyntaxNormalizer())->normalize($source);
        preg_match_all('/(?<!\\S)(\\d+)/u', $normalized, $matches, PREG_OFFSET_CAPTURE);
        if ($matches[1] === []) {
            return new AddressSyntaxPreference(null, AddressSyntaxPreferenceReason::NO_CIVIC_NUMBER);
        }
        preg_match_all('/\\d+/u', $normalized, $allDigitRuns);
        if (count($allDigitRuns[0]) >= 3) {
            return new AddressSyntaxPreference(null, AddressSyntaxPreferenceReason::COMPLEX_CIVIC_DETAILS);
        }

        [$lastNumber, $lastOffset] = $matches[1][array_key_last($matches[1])];
        $street = trim(substr($normalized, 0, $lastOffset));
        $tail = substr($normalized, $lastOffset + strlen($lastNumber));
        $tail = preg_replace('/^\\s+/u', '', $tail) ?? $tail;
        $preferred = null;
        foreach ($numericCandidates as $candidate) {
            if ($candidate->houseNumber?->number === $lastNumber
                && $candidate->streetName === $street
                && $candidate->trailingInformation === $tail) {
                $preferred = $candidate;
                break;
            }
        }
        if ($preferred === null) {
            return new AddressSyntaxPreference(null, AddressSyntaxPreferenceReason::AMBIGUOUS_NUMERIC_BOUNDARY);
        }

        $streetName = trim($preferred->streetName);
        if (preg_match('/(?:\\bINTERNO|\\bINT\\.?|\\bSCALA|\\bPIANO)\\s*$/iu', $streetName) === 1) {
            return new AddressSyntaxPreference(null, AddressSyntaxPreferenceReason::COMPLEX_CIVIC_DETAILS);
        }

        $tokens = preg_split('/\\s+/u', $streetName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) < 2 || preg_match(
            '/\\A(?:VIA|V\\.?|VIALE|V\\.?LE|PIAZZA|P\\.?ZZA|CORSO|C\\.?SO|LARGO|STRADA|S\\.?S\\.?|SS)\\z/iu',
            implode(' ', $tokens),
        ) === 1) {
            return new AddressSyntaxPreference(null, AddressSyntaxPreferenceReason::INCOMPLETE_STREET_NAME);
        }

        $numberMatches = $matches[1];
        $previousNumber = count($numberMatches) > 1
            ? $numberMatches[count($numberMatches) - 2][0]
            : null;
        if ($previousNumber !== null) {
            $previousOffset = $numberMatches[count($numberMatches) - 2][1];
            preg_match_all('/(?<!\\S)(\\d+)/u', $source, $sourceNumberMatches, PREG_OFFSET_CAPTURE);
            $sourcePrevious = $sourceNumberMatches[1][count($sourceNumberMatches[1]) - 2] ?? null;
            $sourceLast = $sourceNumberMatches[1][array_key_last($sourceNumberMatches[1])] ?? null;
            $between = $sourcePrevious !== null && $sourceLast !== null
                ? substr(
                    $source,
                    $sourcePrevious[1] + strlen($sourcePrevious[0]),
                    $sourceLast[1] - ($sourcePrevious[1] + strlen($sourcePrevious[0])),
                )
                : substr($normalized, $previousOffset + strlen($previousNumber), $lastOffset - ($previousOffset + strlen($previousNumber)));

            $numericBoundaryContext = $sourcePrevious !== null && $sourceLast !== null
                ? substr($source, $sourcePrevious[1], $sourceLast[1] - $sourcePrevious[1])
                : substr($normalized, $previousOffset, $lastOffset - $previousOffset);
            if (preg_match('/\\b(?:interno|int\\.?|scala|piano)\\b/i', $between) === 1
                || preg_match('/\\d\\s*\\/\\s*[[:alpha:]]/iu', $numericBoundaryContext) === 1) {
                return new AddressSyntaxPreference(null, AddressSyntaxPreferenceReason::COMPLEX_CIVIC_DETAILS);
            }

            $containsMonthName = preg_match(
                '/\\b(?:GENNAIO|FEBBRAIO|MARZO|APRILE|MAGGIO|GIUGNO|LUGLIO|AGOSTO|SETTEMBRE|OTTOBRE|NOVEMBRE|DICEMBRE)\\b/iu',
                $between,
            ) === 1;
            $commaSeparatesFinalNumber = str_contains($between, ',')
                && preg_match('/\\b[[:alpha:]]{2,}\\b/u', $between) === 1;
            $isNumberedStateRoad = preg_match('/\\bSTRADA\\s+STATALE\\s+\\d+\\s*,\\s*\\d+\\s*$/iu', $source) === 1;
            if (!$containsMonthName && !$commaSeparatesFinalNumber && !$isNumberedStateRoad) {
                return new AddressSyntaxPreference(null, AddressSyntaxPreferenceReason::AMBIGUOUS_NUMERIC_BOUNDARY);
            }

            // Four-digit terminal values after another number often form a date/year
            // in the street name. Leave those cases for directory evidence or review.
            if (strlen($lastNumber) >= 4) {
                return new AddressSyntaxPreference(null, AddressSyntaxPreferenceReason::AMBIGUOUS_NUMERIC_BOUNDARY);
            }
        }

        if (preg_match('/\\bSTRADA\\s+STATALE\\s+\\d+\\s*,\\s*\\d+\\s*\\z/iu', $source) === 1) {
            return new AddressSyntaxPreference($preferred, AddressSyntaxPreferenceReason::FINAL_CIVIC_AFTER_NUMBERED_STATE_ROAD);
        }
        if ($tail !== '' && preg_match('/\\A(?:\\s|[,.-])*\\/?[[:alpha:]][[:alnum:]. -]*\\z/u', $tail) === 1) {
            return new AddressSyntaxPreference($preferred, AddressSyntaxPreferenceReason::FINAL_CIVIC_WITH_SUFFIX);
        }
        if (preg_match('/,\\s*(?:(?:n(?:um(?:ero)?\\.?|°|\\.))\\s*)?\\d+[^\\d]*\\z/iu', $source) === 1) {
            return new AddressSyntaxPreference($preferred, AddressSyntaxPreferenceReason::FINAL_CIVIC_AFTER_COMMA);
        }

        return new AddressSyntaxPreference($preferred, AddressSyntaxPreferenceReason::FINAL_CIVIC_NUMBER);
    }

    private static function collapseWhitespace(string $value): string
    {
        $value = preg_replace('/[\\p{Z}\\x09-\\x0D\\x{0085}]+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
