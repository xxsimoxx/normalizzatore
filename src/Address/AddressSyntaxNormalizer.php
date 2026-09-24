<?php

declare(strict_types=1);

namespace Normalizzatore\Address;

final class AddressSyntaxNormalizer
{
    public function normalize(string $address): string
    {
        $introducer = '(?:numero|num\\.?|n°|n\\.?)';

        $address = preg_replace(
            '/,\\s*(?=(?:' . $introducer . '\\s*)?\\d)/iu',
            ' ',
            $address,
        ) ?? $address;

        return preg_replace(
            '/\\s+' . $introducer . '\\s*(?=\\d)/iu',
            ' ',
            $address,
        ) ?? $address;
    }
}
