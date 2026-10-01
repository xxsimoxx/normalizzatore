<?php

declare(strict_types=1);

namespace Normalizzatore\Address;

/** Applies a small, deterministic set of syntactic rules to existing parser candidates. */
final class AddressSyntaxPreferenceEvaluator
{
    /** @param list<AddressCandidate> $candidates */
    public function evaluate(string $source, array $candidates): AddressSyntaxPreference
    {
        $existingPreference = $this->evaluateExistingRules($source, $candidates);
        if ($existingPreference->hasPreferredCandidate()) {
            return $existingPreference;
        }

        return $this->preferRecognizedStreetDate($source, $candidates) ?? $existingPreference;
    }

    /** @param list<AddressCandidate> $candidates */
    private function evaluateExistingRules(string $source, array $candidates): AddressSyntaxPreference
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

    /** @param list<AddressCandidate> $candidates */
    private function preferRecognizedStreetDate(string $source, array $candidates): ?AddressSyntaxPreference
    {
        // This rule only breaks an existing parser tie; it does not annotate a
        // single-candidate parse that already has no competing interpretation.
        if (count($candidates) < 2) {
            return null;
        }

        $normalized = (new AddressSyntaxNormalizer())->normalize($source);
        $month = '(?:GENNAIO|FEBBRAIO|MARZO|APRILE|MAGGIO|GIUGNO|LUGLIO|AGOSTO|SETTEMBRE|OTTOBRE|NOVEMBRE|DICEMBRE)';
        $day = '(?<day>PRIMO|[0-9]{1,2}|[IVXLCDM]+)';
        $datePattern = '/(?:^|\\s)' . $day . '\\s+' . $month . '(?:\\s+(?<year>18[0-9]{2}|19[0-9]{2}|20[0-9]{2}))?\\z/iu';

        // A terminal day-month[-year] is a complete street-name ending, not a civic.
        foreach ($candidates as $candidate) {
            if ($candidate->houseNumber === null
                && $candidate->trailingInformation === ''
                && $candidate->streetName !== ''
                && $this->endsWithRecognizedDate($candidate->streetName, $datePattern)
                && trim($candidate->streetName) === trim($normalized)) {
                return new AddressSyntaxPreference($candidate, AddressSyntaxPreferenceReason::NUMERIC_STREET_DATE);
            }
        }

        // A following civic must be 1..999; this bounds the interpretation and avoids
        // treating a large terminal value (for example 1470) as an address number.
        $matchingCivics = [];
        foreach ($candidates as $candidate) {
            $number = $candidate->houseNumber?->number;
            if ($number === null || preg_match('/\\A[0-9]{1,3}\\z/', $number) !== 1 || (int) $number === 0) {
                continue;
            }
            if (!$this->endsWithRecognizedDate($candidate->streetName, $datePattern)) {
                continue;
            }
            if ($candidate->trailingInformation !== ''
                && preg_match('/\\A(?:[[:space:]]*[A-Za-z]|\\/[A-Za-z])\\z/u', $candidate->trailingInformation) !== 1) {
                continue;
            }

            $matchingCivics[] = $candidate;
        }

        if (count($matchingCivics) !== 1) {
            return null;
        }

        return new AddressSyntaxPreference($matchingCivics[0], AddressSyntaxPreferenceReason::NUMERIC_STREET_DATE);
    }

    private function endsWithRecognizedDate(string $streetName, string $datePattern): bool
    {
        if (preg_match($datePattern, $streetName, $matches) !== 1) {
            return false;
        }

        $day = mb_strtoupper($matches['day'], 'UTF-8');
        if ($day === 'PRIMO') {
            return true;
        }
        if (ctype_digit($day)) {
            return (int) $day >= 1 && (int) $day <= 31;
        }

        for ($value = 1; $value <= 31; ++$value) {
            if ($day === $this->romanDay($value)) {
                return true;
            }
        }

        return false;
    }

    private function romanDay(int $value): string
    {
        $numerals = [
            10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I',
        ];
        $result = '';
        foreach ($numerals as $amount => $numeral) {
            while ($value >= $amount) {
                $result .= $numeral;
                $value -= $amount;
            }
        }

        return $result;
    }

    private static function collapseWhitespace(string $value): string
    {
        $value = preg_replace('/[\\p{Z}\\x09-\\x0D\\x{0085}]+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
