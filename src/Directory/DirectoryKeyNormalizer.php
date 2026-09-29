<?php

declare(strict_types=1);

namespace Normalizzatore\Directory;

use InvalidArgumentException;

/**
 * Creates conservative lookup keys. Whitespace is Unicode separator characters,
 * ASCII tab through carriage return, and Unicode NEXT LINE (U+0085).
 */
final readonly class DirectoryKeyNormalizer
{
    public function normalize(string $value): string
    {
        $collapsed = preg_replace('/[\p{Z}\x09-\x0D\x{0085}]+/u', ' ', $value);
        if ($collapsed === null) {
            throw new InvalidArgumentException('Directory key contains invalid UTF-8.');
        }

        return mb_strtoupper(trim($collapsed), 'UTF-8');
    }
}
