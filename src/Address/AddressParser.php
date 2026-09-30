<?php

declare(strict_types=1);

namespace Normalizzatore\Address;

final class AddressParser
{
    public function __construct(
        private readonly AddressSyntaxNormalizer $syntaxNormalizer = new AddressSyntaxNormalizer(),
        private readonly AddressSyntaxPreferenceEvaluator $preferenceEvaluator = new AddressSyntaxPreferenceEvaluator(),
    ) {
    }

    public function parse(AddressInput $input): ParsedAddress
    {
        $normalized = $this->syntaxNormalizer->normalize($input->vianum);
        $address = ltrim($normalized);

        if (preg_match('/(?:^|\\s)SNC\\s*$/iu', $address, $sncMatch) === 1) {
            $street = trim(substr($address, 0, -strlen($sncMatch[0])));
            $candidate = new AddressCandidate($street, null, '');
            $candidates = [$candidate];

            return new ParsedAddress(
                $input,
                $normalized,
                $candidates,
                true,
                $this->preferenceEvaluator->evaluate($input->vianum, $candidates),
            );
        }

        /** @var array<string, AddressCandidate> $candidates */
        $candidates = [];

        $completeStreet = trim($address);
        if ($completeStreet !== '') {
            $this->addCandidate($candidates, new AddressCandidate($completeStreet, null, ''));
        }

        preg_match_all('/(?<!\\S)(\\d+)/u', $address, $numberMatches, PREG_OFFSET_CAPTURE);

        foreach ($numberMatches[1] as [$number, $offset]) {
            $street = trim(substr($address, 0, $offset));
            if ($street === '') {
                continue;
            }

            $tailOffset = $offset + strlen($number);
            $trailingInformation = substr($address, $tailOffset);
            $trailingInformation = preg_replace('/^\\s+/u', '', $trailingInformation) ?? $trailingInformation;

            $this->addCandidate(
                $candidates,
                new AddressCandidate($street, new HouseNumber($number), $trailingInformation),
            );
        }

        $candidateList = array_values($candidates);

        return new ParsedAddress(
            $input,
            $normalized,
            $candidateList,
            false,
            $this->preferenceEvaluator->evaluate($input->vianum, $candidateList),
        );
    }

    /**
     * @param array<string, AddressCandidate> $candidates
     */
    private function addCandidate(array &$candidates, AddressCandidate $candidate): void
    {
        $key = serialize([
            $candidate->streetName,
            $candidate->houseNumber?->number,
            $candidate->trailingInformation,
        ]);

        $candidates[$key] = $candidate;
    }
}
