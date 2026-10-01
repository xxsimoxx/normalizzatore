<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Text;

use Normalizzatore\Text\OrthographyNormalizer;
use PHPUnit\Framework\TestCase;

final class OrthographyNormalizerTest extends TestCase
{
    public function testCanonicalizesSupportedVowelAndApostropheForms(): void
    {
        $normalizer = new OrthographyNormalizer();
        foreach ([
            ["À à A' a' A’ a’ A‘ a‘ A´ a´ A` a`", "A' A' A' A' A' A' A' A' A' A' A' A'"],
            ["È É è é E' e' E’ e’ E‘ e‘ E´ e´ E` e`", "E' E' E' E' E' E' E' E' E' E' E' E' E' E'"],
            ["Ì Í ì í I' i' I’ i’ I‘ i‘ I´ i´ I` i`", "I' I' I' I' I' I' I' I' I' I' I' I' I' I'"],
            ["Ò Ó ò ó O' o' O’ o’ O‘ o‘ O´ o´ O` o`", "O' O' O' O' O' O' O' O' O' O' O' O' O' O'"],
            ["Ù Ú ù ú U' u' U’ u’ U‘ u‘ U´ u´ U` u`", "U' U' U' U' U' U' U' U' U' U' U' U' U' U'"],
        ] as [$input, $expected]) {
            self::assertSame($expected, $normalizer->normalize($input));
        }
    }

    public function testCanonicalizesSupportedDecomposedVowels(): void
    {
        $input = "A\u{0300} a\u{0301} E\u{0300} e\u{0301} I\u{0300} i\u{0301} O\u{0300} o\u{0301} U\u{0300} u\u{0301}";
        self::assertSame("A' A' E' E' I' I' O' O' U' U'", (new OrthographyNormalizer())->normalize($input));
    }

    public function testPreservesOtherDiacriticsAndQuotationMarks(): void
    {
        self::assertSame('Â Ê Ô Ç " “ ” „ « »', (new OrthographyNormalizer())->normalize('â ê ô ç " “ ” „ « »'));
    }

    public function testAppliesUnicodeUppercaseAndConservativeWhitespace(): void
    {
        self::assertSame("VIA D'ANNUNZIO", (new OrthographyNormalizer())->normalize("  Via   D`Annunzio\t"));
    }
}
