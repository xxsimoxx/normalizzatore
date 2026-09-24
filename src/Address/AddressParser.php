<?php

declare(strict_types=1);

namespace Normalizzatore\Address;

final class AddressParser
{
    public function __construct(
        private readonly AddressSyntaxNormalizer $syntaxNormalizer = new AddressSyntaxNormalizer(),
    ) {
    }

    public function parse(AddressInput $input): ParsedAddress
    {
        $address = ltrim($this->syntaxNormalizer->normalize($input->vianum));

        if (preg_match('/(?:^|\\s)SNC\\s*$/iu', $address, $sncMatch) === 1) {
            $street = trim(substr($address, 0, -strlen($sncMatch[0])));

            return new ParsedAddress($input, $street, null, '', true);
        }

        preg_match_all('/\\d+/u', $address, $numberMatches, PREG_OFFSET_CAPTURE);

        foreach ($numberMatches[0] as [$number, $offset]) {
            $street = trim(substr($address, 0, $offset));
            if ($this->containsStreetName($street) === false) {
                continue;
            }

            $tailOffset = $offset + strlen($number);
            $trailingInformation = substr($address, $tailOffset);
            $trailingInformation = preg_replace('/^\\s+/u', '', $trailingInformation) ?? $trailingInformation;

            return new ParsedAddress(
                $input,
                $street,
                new HouseNumber($number),
                $trailingInformation,
                false,
            );
        }

        return new ParsedAddress($input, trim($address), null, '', false);
    }

    private function containsStreetName(string $street): bool
    {
        preg_match_all('/[\\p{L}\\p{M}]+/u', $street, $words);

        // Requiring two words avoids treating numbers in names such as
        // "Via 8 Luglio" or "Via 20 Settembre" as a civic number.
        return count($words[0]) >= 2;
    }
}
