<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Directory;

use Normalizzatore\Directory\DirectoryKeyNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DirectoryKeyNormalizerTest extends TestCase
{
    #[DataProvider('normalizationCases')]
    public function testNormalizesOnlyWhitespaceAndUnicodeCase(string $input, string $expected): void
    {
        self::assertSame($expected, (new DirectoryKeyNormalizer())->normalize($input));
    }

    public static function normalizationCases(): iterable
    {
        yield 'already normalized' => ['VIA ROMA', 'VIA ROMA'];
        yield 'lowercase' => ['via roma', 'VIA ROMA'];
        yield 'surrounding whitespace' => ['  Via Roma  ', 'VIA ROMA'];
        yield 'repeated spaces' => ['Via   Roma', 'VIA ROMA'];
        yield 'tab and newline' => ["Via\tRoma\nCentro", 'VIA ROMA CENTRO'];
        yield 'nonbreaking space' => ["Via\u{00A0}Roma", 'VIA ROMA'];
        yield 'unicode next line' => ["Via\u{0085}Roma", 'VIA ROMA'];
        yield 'ideographic space' => ["Via\u{3000}Roma", 'VIA ROMA'];
        yield 'accented Italian' => ['Magrè sulla strada del vino', 'MAGRÈ SULLA STRADA DEL VINO'];
        yield 'apostrophe preserved' => ["Cafe'", "CAFE'"];
        yield 'hyphen preserved' => ['Via San-Pietro', 'VIA SAN-PIETRO'];
        yield 'periods preserved' => ['S.S. Audiface', 'S.S. AUDIFACE'];
        yield 'slash preserved' => ['Via 4/A', 'VIA 4/A'];
    }

    public function testDoesNotExpandAbbreviationOrRemoveAccents(): void
    {
        $normalizer = new DirectoryKeyNormalizer();

        self::assertSame('S.S.', $normalizer->normalize('S.S.'));
        self::assertNotSame('SANTI', $normalizer->normalize('S.S.'));
        self::assertSame('MAGRÈ', $normalizer->normalize('Magrè'));
        self::assertNotSame('MAGRE', $normalizer->normalize('Magrè'));
    }
}
