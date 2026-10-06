<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Resolution;

use Normalizzatore\Resolution\OptimalStringAlignmentDistance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OptimalStringAlignmentDistanceTest extends TestCase
{
    #[DataProvider('distanceExamples')]
    public function testComputesUnicodeCodePointOptimalStringAlignmentDistance(string $left, string $right, int $expected): void
    {
        $distance = new OptimalStringAlignmentDistance();

        self::assertSame($expected, $distance->distance($left, $right));
        self::assertSame($expected, $distance->distance($right, $left));
    }

    public static function distanceExamples(): iterable
    {
        yield 'identical' => ['FERMI', 'FERMI', 0];
        yield 'extra doubled consonant' => ['CAPUCCINA', 'CAPPUCCINA', 1];
        yield 'extra letter' => ['GUINIZZELLI', 'GUINIZELLI', 1];
        yield 'substitution' => ['TOLSTOI', 'TOLSTOJ', 1];
        yield 'adjacent transposition' => ['GARIBLADI', 'GARIBALDI', 1];
        yield 'insertion' => ['ALFA', 'ALXFA', 1];
        yield 'deletion' => ['ALXFA', 'ALFA', 1];
        yield 'two edits' => ['ALFA', 'BETA', 3];
        yield 'empty left' => ['', 'VIA', 3];
        yield 'empty both' => ['', '', 0];
        yield 'multibyte code point substitution' => ['PÈRA', 'PERA', 1];
    }

    public function testRejectsInvalidUtf8(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OptimalStringAlignmentDistance())->distance("CITTA\xFF", 'CITTA');
    }
}
