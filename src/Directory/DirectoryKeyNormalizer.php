<?php

declare(strict_types=1);

namespace Normalizzatore\Directory;

use InvalidArgumentException;
use Normalizzatore\Text\OrthographyNormalizer;

/**
 * Creates lookup keys using explicit orthographic equivalences and conservative
 * whitespace normalization (Unicode separators, ASCII tab through carriage return,
 * and Unicode NEXT LINE U+0085).
 */
final readonly class DirectoryKeyNormalizer
{
    public function __construct(private OrthographyNormalizer $orthographyNormalizer = new OrthographyNormalizer())
    {
    }

    public function normalize(string $value): string
    {
        try {
            return $this->orthographyNormalizer->normalize($value);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('Directory key contains invalid UTF-8.');
        }
    }

    /** @return array<string, string> */
    public function orthographicReplacements(): array
    {
        return $this->orthographyNormalizer->orthographicReplacements();
    }

    /** @return list<string> */
    public function orthographicTriggerCharacters(): array
    {
        return $this->orthographyNormalizer->orthographicTriggerCharacters();
    }
}
