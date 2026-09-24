<?php

declare(strict_types=1);

namespace Normalizzatore\Address;

final class AddressParser
{
    /**
     * Words that signal that text after a civic number is address detail.
     * Other trailing words are kept as part of the street text to avoid
     * interpreting digits inside a street name as a civic number.
     */
    private const TRAILING_DETAIL_START = 'interno|int\\.?|scala|sc\\.?|piano|p\\.?|palazzina|pal\\.?|edificio|ed\\.?|lotto|c/o';

    public function parse(AddressInput $input): ParsedAddress
    {
        $address = trim($input->vianum);

        if (preg_match('/(?:^|\\s)SNC\\s*$/iu', $address, $sncMatch) === 1) {
            $street = trim(substr($address, 0, -strlen($sncMatch[0])));

            return new ParsedAddress($input, $street, null, '', true);
        }

        $civicNumber = '(?<number>\\d+)'
            . '(?:(?<rangeSeparator>\\s*-\\s*)(?<rangeEnd>\\d+)'
            . '|(?<slashSeparator>\\s*/\\s*)(?<slashSuffix>[A-Za-z])'
            . '|(?<spaceSeparator>\\s+)(?<spaceSuffix>[A-Za-z])(?![A-Za-z]))?';
        $trailingPattern = '~^(?<street>.*\\S)\\s+' . $civicNumber
            . '\\s+(?<trailing>(?:(?:' . self::TRAILING_DETAIL_START . ')\\b.*|[A-Za-z]))$~iu';
        $plainPattern = '~^(?<street>.*\\S)\\s+' . $civicNumber . '$~iu';

        $matchedTrailing = preg_match($trailingPattern, $address, $matches, PREG_UNMATCHED_AS_NULL) === 1;
        if ($matchedTrailing
            && preg_match('/^[A-Za-z]$/D', $matches['trailing']) === 1
            && preg_match('/\\d/', $matches['street']) !== 1) {
            $matchedTrailing = false;
        }

        if (!$matchedTrailing && preg_match($plainPattern, $address, $matches, PREG_UNMATCHED_AS_NULL) !== 1) {
            return new ParsedAddress($input, $address, null, '', false);
        }

        $separator = null;
        $suffix = null;
        $rangeEnd = null;

        if ($matches['rangeSeparator'] !== null) {
            $separator = trim($matches['rangeSeparator']);
            $rangeEnd = $matches['rangeEnd'];
        } elseif ($matches['slashSeparator'] !== null) {
            $separator = trim($matches['slashSeparator']);
            $suffix = $matches['slashSuffix'];
        } elseif ($matches['spaceSeparator'] !== null) {
            $separator = ' ';
            $suffix = $matches['spaceSuffix'];
        }

        $numberRaw = $matches['number']
            . ($matches['rangeSeparator'] ?? '') . ($matches['rangeEnd'] ?? '')
            . ($matches['slashSeparator'] ?? '') . ($matches['slashSuffix'] ?? '')
            . ($matches['spaceSeparator'] ?? '') . ($matches['spaceSuffix'] ?? '');

        return new ParsedAddress(
            $input,
            trim($matches['street']),
            new HouseNumber($matches['number'], $separator, $suffix, $rangeEnd, trim($numberRaw)),
            trim($matches['trailing'] ?? ''),
            false,
        );
    }
}
