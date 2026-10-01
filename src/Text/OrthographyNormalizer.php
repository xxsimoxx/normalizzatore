<?php

declare(strict_types=1);

namespace Normalizzatore\Text;

use InvalidArgumentException;

/** Canonicalizes only the explicitly supported Italian accent and apostrophe forms. */
final readonly class OrthographyNormalizer
{
    /** @var array<string, string> */
    private array $replacements;

    /** @var list<string> */
    private array $triggerCharacters;

    public function __construct()
    {
        $this->replacements = $this->decomposedVowels() + [
            '’' => "'",
            '‘' => "'",
            '´' => "'",
            '`' => "'",
        ] + $this->precomposedVowels();

        $characters = [];
        foreach (array_keys($this->replacements) as $sequence) {
            foreach (preg_split('//u', $sequence, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
                if (preg_match('/^[\x00-\x7F]$/D', $character) === 1 && ctype_alnum($character)) {
                    continue;
                }
                $characters[$character] = $character;
            }
        }
        $this->triggerCharacters = array_values($characters);
    }

    public function normalize(string $value): string
    {
        $value = strtr($value, $this->replacements);

        $collapsed = preg_replace('/[\p{Z}\x09-\x0D\x{0085}]+/u', ' ', $value);
        if ($collapsed === null) {
            throw new InvalidArgumentException('Orthographic value contains invalid UTF-8.');
        }

        return mb_strtoupper(trim($collapsed), 'UTF-8');
    }

    /** @return array<string, string> Explicit replacements applied before Unicode uppercasing. */
    public function orthographicReplacements(): array
    {
        return $this->replacements;
    }

    /** @return list<string> Characters whose presence can trigger an orthographic replacement. */
    public function orthographicTriggerCharacters(): array
    {
        return $this->triggerCharacters;
    }

    /** @return array<string, string> */
    private function precomposedVowels(): array
    {
        return [
            'À' => "A'", 'à' => "A'", 'Á' => "A'", 'á' => "A'",
            'È' => "E'", 'è' => "E'", 'É' => "E'", 'é' => "E'",
            'Ì' => "I'", 'ì' => "I'", 'Í' => "I'", 'í' => "I'",
            'Ò' => "O'", 'ò' => "O'", 'Ó' => "O'", 'ó' => "O'",
            'Ù' => "U'", 'ù' => "U'", 'Ú' => "U'", 'ú' => "U'",
        ];
    }

    /** @return array<string, string> */
    private function decomposedVowels(): array
    {
        $mapping = [];
        foreach ([
            'A' => ["\u{0300}", "\u{0301}"],
            'E' => ["\u{0300}", "\u{0301}"],
            'I' => ["\u{0300}", "\u{0301}"],
            'O' => ["\u{0300}", "\u{0301}"],
            'U' => ["\u{0300}", "\u{0301}"],
        ] as $vowel => $marks) {
            foreach ([$vowel, mb_strtolower($vowel, 'UTF-8')] as $case) {
                foreach ($marks as $mark) {
                    $mapping[$case . $mark] = $vowel . "'";
                }
            }
        }

        return $mapping;
    }
}
