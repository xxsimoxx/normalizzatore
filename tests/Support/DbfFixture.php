<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Support;

final class DbfFixture
{
    /**
     * @param list<array{vianum: string, cap: string, citta: string, pr: string, pari_dispa: string, civico_da: string, civico_a: string, deleted?: bool}> $rows
     */
    public static function write(string $path, array $rows): void
    {
        $fields = [
            'VIANUM' => 88,
            'CAP' => 6,
            'CITTA' => 34,
            'PR' => 9,
            'PARI_DISPA' => 2,
            'CIVICO_DA' => 6,
            'CIVICO_A' => 6,
        ];
        $headerLength = 32 + count($fields) * 32 + 1;
        $recordLength = 1 + array_sum($fields);
        $header = str_repeat("\0", 32);
        $header[0] = "\x03";
        $header[1] = chr(126);
        $header[2] = chr(9);
        $header[3] = chr(29);
        $header = substr_replace($header, pack('V', count($rows)), 4, 4);
        $header = substr_replace($header, pack('v', $headerLength), 8, 2);
        $header = substr_replace($header, pack('v', $recordLength), 10, 2);
        $header[29] = "\x02";

        foreach ($fields as $name => $length) {
            $descriptor = str_pad($name, 11, "\0") . 'C' . str_repeat("\0", 4) . chr($length) . "\0" . str_repeat("\0", 14);
            $header .= $descriptor;
        }
        $header .= "\x0D";

        foreach ($rows as $row) {
            $header .= !empty($row['deleted']) ? '*' : ' ';
            foreach ($fields as $field => $length) {
                $key = match ($field) {
                    'VIANUM' => 'vianum',
                    'CAP' => 'cap',
                    'CITTA' => 'citta',
                    'PR' => 'pr',
                    'PARI_DISPA' => 'pari_dispa',
                    'CIVICO_DA' => 'civico_da',
                    'CIVICO_A' => 'civico_a',
                };
                $encoded = iconv('UTF-8', 'CP850', $row[$key]);
                if ($encoded === false || strlen($encoded) > $length) {
                    throw new \RuntimeException(sprintf('Fixture value for %s cannot fit in CP850 field.', $field));
                }
                $header .= str_pad($encoded, $length, ' ');
            }
        }

        file_put_contents($path, $header . "\x1A");
    }

    /** @return list<array{vianum: string, cap: string, citta: string, pr: string, pari_dispa: string, civico_da: string, civico_a: string, deleted?: bool}> */
    public static function rows(): array
    {
        $base = [
            'vianum' => 'VIA CITTÀ', 'cap' => '00165', 'citta' => 'ROMA', 'pr' => 'RM',
            'pari_dispa' => 'D', 'civico_da' => '1', 'civico_a' => '19',
        ];

        return [
            $base,
            ['vianum' => 'VIA DISUS', 'cap' => 'DISUS', 'citta' => 'ROMA', 'pr' => 'RM', 'pari_dispa' => 'P', 'civico_da' => '2', 'civico_a' => '20'],
            ['vianum' => 'VIA X', 'cap' => 'X', 'citta' => 'ROMA', 'pr' => 'RM', 'pari_dispa' => 'T', 'civico_da' => '', 'civico_a' => ''],
            ['vianum' => 'VIA VUOTA', 'cap' => '', 'citta' => 'ROMA', 'pr' => 'RM', 'pari_dispa' => 'KM', 'civico_da' => '62,000', 'civico_a' => ''],
            ['vianum' => 'VIA R', 'cap' => '00100', 'citta' => 'ROMA', 'pr' => 'RM', 'pari_dispa' => 'R', 'civico_da' => '4/A', 'civico_a' => '38A'],
            ['vianum' => 'VIA SUFFISSO', 'cap' => '00101', 'citta' => 'ROMA', 'pr' => 'RM', 'pari_dispa' => 'km', 'civico_da' => '5 R', 'civico_a' => '3/4'],
            $base,
            ['vianum' => 'RECORD ELIMINATO', 'cap' => '00000', 'citta' => 'TEST', 'pr' => 'TT', 'pari_dispa' => 'D', 'civico_da' => '1', 'civico_a' => '1', 'deleted' => true],
        ];
    }
}
