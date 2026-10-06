<?php

declare(strict_types=1);

namespace Normalizzatore\Address;

use InvalidArgumentException;

/** Canonical, tokenized form of a street name whose leading type is recognized. */
final readonly class TokenizedStreetName
{
    /**
     * @param list<string> $nominalTokens
     */
    public function __construct(
        public string $sourceName,
        public string $canonicalName,
        public StreetType $streetType,
        public array $nominalTokens,
    ) {
        if (!array_is_list($nominalTokens) || $nominalTokens === []) {
            throw new InvalidArgumentException('A tokenized street name must contain nominal tokens in a list.');
        }
        foreach ($nominalTokens as $token) {
            if (!is_string($token) || $token === '' || preg_match('/\s/u', $token) === 1) {
                throw new InvalidArgumentException('Nominal street tokens must be non-empty and contain no whitespace.');
            }
            if (mb_strtoupper($token, 'UTF-8') !== $token) {
                throw new InvalidArgumentException('Canonical nominal street tokens must be uppercase.');
            }
        }
        if ($canonicalName !== $streetType->value . ' ' . implode(' ', $nominalTokens)) {
            throw new InvalidArgumentException('Canonical street name must agree with its type and nominal tokens.');
        }
        if (preg_match('/\s/u', $canonicalName) === 1
            && preg_match('/\A\S+(?: \S+)+\z/u', $canonicalName) !== 1) {
            throw new InvalidArgumentException('Canonical street name must use single spaces between tokens.');
        }
    }

    public function tokenCount(): int
    {
        return count($this->nominalTokens);
    }
}
