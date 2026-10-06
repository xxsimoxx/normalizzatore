<?php

declare(strict_types=1);

namespace Normalizzatore\Csv;

use InvalidArgumentException;

final class NormalizeArguments
{
    /** @param list<string> $arguments Arguments after the `normalize` command name. */
    public static function parse(array $arguments): NormalizeOptions
    {
        if (count($arguments) < 2) {
            throw new InvalidArgumentException('Usage: bin/normalizzatore normalize input.csv output.csv [--delimiter=";"] [--fuzzy]');
        }
        $delimiter = ';';
        $delimiterSeen = false;
        $fuzzy = false;
        foreach (array_slice($arguments, 2) as $option) {
            if ($option === '--fuzzy') {
                if ($fuzzy) {
                    throw new InvalidArgumentException('Option --fuzzy may be specified only once.');
                }
                $fuzzy = true;
                continue;
            }
            if (str_starts_with($option, '--delimiter=')) {
                if ($delimiterSeen) {
                    throw new InvalidArgumentException('Option --delimiter may be specified only once.');
                }
                $delimiterSeen = true;
                $delimiter = substr($option, strlen('--delimiter='));
                if (strlen($delimiter) >= 2 && (($delimiter[0] === '"' && $delimiter[-1] === '"') || ($delimiter[0] === "'" && $delimiter[-1] === "'"))) {
                    $delimiter = substr($delimiter, 1, -1);
                }
                continue;
            }
            throw new InvalidArgumentException(sprintf('Unsupported option: %s. Supported options are --delimiter=";" and --fuzzy.', $option));
        }
        if (!in_array($delimiter, [',', ';', "\t"], true)) {
            throw new InvalidArgumentException('Delimiter must be one character: comma, semicolon, or tab.');
        }

        return new NormalizeOptions($arguments[0], $arguments[1], $delimiter, $fuzzy);
    }
}
