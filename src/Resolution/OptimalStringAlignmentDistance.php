<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use InvalidArgumentException;

/** Unicode-code-point Optimal String Alignment edit distance. */
final readonly class OptimalStringAlignmentDistance
{
    public function distance(string $left, string $right): int
    {
        if (!mb_check_encoding($left, 'UTF-8') || !mb_check_encoding($right, 'UTF-8')) {
            throw new InvalidArgumentException('OSA distance inputs must be valid UTF-8.');
        }

        $leftCharacters = mb_str_split($left, 1, 'UTF-8');
        $rightCharacters = mb_str_split($right, 1, 'UTF-8');
        $leftLength = count($leftCharacters);
        $rightLength = count($rightCharacters);
        $matrix = [];

        for ($i = 0; $i <= $leftLength; ++$i) {
            $matrix[$i] = array_fill(0, $rightLength + 1, 0);
            $matrix[$i][0] = $i;
        }
        for ($j = 0; $j <= $rightLength; ++$j) {
            $matrix[0][$j] = $j;
        }

        for ($i = 1; $i <= $leftLength; ++$i) {
            for ($j = 1; $j <= $rightLength; ++$j) {
                $cost = $leftCharacters[$i - 1] === $rightCharacters[$j - 1] ? 0 : 1;
                $matrix[$i][$j] = min(
                    $matrix[$i - 1][$j] + 1,
                    $matrix[$i][$j - 1] + 1,
                    $matrix[$i - 1][$j - 1] + $cost,
                );

                if ($i > 1 && $j > 1
                    && $leftCharacters[$i - 1] === $rightCharacters[$j - 2]
                    && $leftCharacters[$i - 2] === $rightCharacters[$j - 1]) {
                    $matrix[$i][$j] = min($matrix[$i][$j], $matrix[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $matrix[$leftLength][$rightLength];
    }
}
