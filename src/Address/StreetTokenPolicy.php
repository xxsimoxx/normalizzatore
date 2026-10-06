<?php

declare(strict_types=1);

namespace Normalizzatore\Address;

/** Small data-backed set of function words that fuzzy matching must leave exact. */
final readonly class StreetTokenPolicy
{
    /** @var list<string> */
    private const FUNCTIONAL_TOKENS = [
        'A', 'AL', 'ALLA', 'DA', 'DAL', 'DALLA', 'DE', 'DEGLI', 'DEI', 'DEL',
        'DELLA', 'DELLE', 'DELLO', 'DI', 'IN', 'NEI', 'NEL', 'NELLA', 'SAN',
        'SANTA', "SANT'", 'SU', 'SUL', 'SULLA',
    ];

    public function isFunctional(string $token): bool
    {
        return in_array($token, self::FUNCTIONAL_TOKENS, true);
    }

    /** @return list<string> */
    public function functionalTokens(): array
    {
        return self::FUNCTIONAL_TOKENS;
    }
}
