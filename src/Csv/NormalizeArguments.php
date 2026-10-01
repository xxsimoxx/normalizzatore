<?php

declare(strict_types=1);

namespace Normalizzatore\Csv;

use InvalidArgumentException;

final class NormalizeArguments
{
    /** @param list<string> $arguments Arguments after the `normalize` command name. */
    public static function parse(array $arguments): NormalizeOptions
    {
        if (count($arguments) < 2 || count($arguments) > 3) {
            throw new InvalidArgumentException('Usage: bin/normalizzatore normalize input.csv output.csv [--delimiter=";"]');
        }
        $delimiter = ';';
        if (isset($arguments[2])) {
            if (!str_starts_with($arguments[2], '--delimiter=')) {
                throw new InvalidArgumentException('The only supported option is --delimiter=";".');
            }
            $delimiter = substr($arguments[2], strlen('--delimiter='));
            if (strlen($delimiter) >= 2 && (($delimiter[0] === '"' && $delimiter[-1] === '"') || ($delimiter[0] === "'" && $delimiter[-1] === "'"))) {
                $delimiter = substr($delimiter, 1, -1);
            }
        }
        if (!in_array($delimiter, [',', ';', "\t"], true)) {
            throw new InvalidArgumentException('Delimiter must be one character: comma, semicolon, or tab.');
        }

        return new NormalizeOptions($arguments[0], $arguments[1], $delimiter);
    }
}
