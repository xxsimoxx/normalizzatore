<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Support;

use Normalizzatore\Directory\SqliteDirectoryImporter;
use PDO;

final class SqliteDirectoryFixture
{
    /**
     * @param list<array{vianum: string, cap: string, citta: string, pr: string, pari_dispa: string, civico_da: string, civico_a: string}> $rows
     */
    public static function create(string $path, array $rows): void
    {
        $pdo = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec(<<<'SQL'
            CREATE TABLE directory_entries (
                id INTEGER PRIMARY KEY,
                vianum TEXT NOT NULL,
                cap TEXT NOT NULL,
                citta TEXT NOT NULL,
                pr TEXT NOT NULL,
                pari_dispa TEXT NOT NULL,
                civico_da TEXT NOT NULL,
                civico_a TEXT NOT NULL,
                vianum_key TEXT NOT NULL,
                citta_key TEXT NOT NULL,
                pr_key TEXT NOT NULL
            )
            SQL);
        $pdo->exec('CREATE TABLE directory_metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->prepare('INSERT INTO directory_metadata (key, value) VALUES (?, ?)')->execute([
            'schema_version',
            SqliteDirectoryImporter::SCHEMA_VERSION,
        ]);
        $insert = $pdo->prepare(<<<'SQL'
            INSERT INTO directory_entries
            (vianum, cap, citta, pr, pari_dispa, civico_da, civico_a, vianum_key, citta_key, pr_key)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            SQL);
        foreach ($rows as $row) {
            $insert->execute([
                $row['vianum'], $row['cap'], $row['citta'], $row['pr'], $row['pari_dispa'], $row['civico_da'], $row['civico_a'],
                self::legacyKey($row['vianum']), self::legacyKey($row['citta']), self::legacyKey($row['pr']),
            ]);
        }
        $pdo->exec('CREATE INDEX idx_directory_entries_lookup_key ON directory_entries (vianum_key, citta_key, pr_key)');
    }

    private static function legacyKey(string $value): string
    {
        $collapsed = preg_replace('/[\p{Z}\x09-\x0D\x{0085}]+/u', ' ', $value);
        if ($collapsed === null) {
            throw new \InvalidArgumentException('Fixture value contains invalid UTF-8.');
        }

        return mb_strtoupper(trim($collapsed), 'UTF-8');
    }

    /** @return array{vianum: string, cap: string, citta: string, pr: string, pari_dispa: string, civico_da: string, civico_a: string} */
    public static function row(string $street, string $cap, string $city, string $province, string $parity, string $from, string $to): array
    {
        return [
            'vianum' => $street, 'cap' => $cap, 'citta' => $city, 'pr' => $province,
            'pari_dispa' => $parity, 'civico_da' => $from, 'civico_a' => $to,
        ];
    }
}
