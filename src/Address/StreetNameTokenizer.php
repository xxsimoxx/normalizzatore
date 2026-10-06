<?php

declare(strict_types=1);

namespace Normalizzatore\Address;

use InvalidArgumentException;
use Normalizzatore\Directory\DirectoryKeyNormalizer;

/** Canonicalizes and splits a street-only value without parsing civic information. */
final readonly class StreetNameTokenizer
{
    public function __construct(private DirectoryKeyNormalizer $keyNormalizer = new DirectoryKeyNormalizer())
    {
    }

    public function tokenize(string $streetName): ?TokenizedStreetName
    {
        try {
            $canonicalName = $this->keyNormalizer->normalize($streetName);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($canonicalName === '') {
            return null;
        }

        $tokens = preg_split('/\s+/u', $canonicalName, -1, PREG_SPLIT_NO_EMPTY);
        if ($tokens === false || count($tokens) < 2) {
            return null;
        }

        $streetType = StreetType::tryFrom($tokens[0]);
        if ($streetType === null) {
            return null;
        }

        $nominalTokens = array_slice($tokens, 1);

        return new TokenizedStreetName(
            $streetName,
            $canonicalName,
            $streetType,
            $nominalTokens,
        );
    }
}
