<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Address;

use Normalizzatore\Address\AddressCandidate;
use Normalizzatore\Address\AddressInput;
use Normalizzatore\Address\AddressParser;
use Normalizzatore\Address\HouseNumber;
use Normalizzatore\Address\ParsedAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddressParserTest extends TestCase
{
    private AddressParser $parser;

    protected function setUp(): void
    {
        $this->parser = new AddressParser();
    }

    #[DataProvider('addressExamples')]
    public function testCreatesExpectedCandidateSet(
        string $vianum,
        string $normalized,
        array $expectedCandidates,
        bool $hasNoHouseNumber = false,
    ): void {
        $input = new AddressInput($vianum, '00123', 'Città', 'rm');
        $parsed = $this->parser->parse($input);

        self::assertSame($normalized, $parsed->normalizedVianum);
        $expectedCandidates = $this->sortSignatures($expectedCandidates);
        self::assertSame($expectedCandidates, $this->candidateSignatures($parsed->candidates));
        self::assertSame($hasNoHouseNumber, $parsed->hasNoHouseNumber);
        self::assertSame($input, $parsed->input);
        self::assertSame($vianum, $parsed->input->vianum);
        self::assertSame('00123', $parsed->input->cap);
        self::assertSame('Città', $parsed->input->city);
        self::assertSame('rm', $parsed->input->province);
    }

    public static function addressExamples(): iterable
    {
        yield 'Via Roma 15' => [
            'Via Roma 15', 'Via Roma 15', [
                ['Via Roma 15', null, ''],
                ['Via Roma', '15', ''],
            ],
        ];
        yield 'Via Roma 15 A' => [
            'Via Roma 15 A', 'Via Roma 15 A', [
                ['Via Roma 15 A', null, ''],
                ['Via Roma', '15', 'A'],
            ],
        ];
        yield 'Via Roma 15/A' => [
            'Via Roma 15/A', 'Via Roma 15/A', [
                ['Via Roma 15/A', null, ''],
                ['Via Roma', '15', '/A'],
            ],
        ];
        yield 'Via Roma 15A' => [
            'Via Roma 15A', 'Via Roma 15A', [
                ['Via Roma 15A', null, ''],
                ['Via Roma', '15', 'A'],
            ],
        ];
        yield 'Via Roma 15 Rosso' => [
            'Via Roma 15 Rosso', 'Via Roma 15 Rosso', [
                ['Via Roma 15 Rosso', null, ''],
                ['Via Roma', '15', 'Rosso'],
            ],
        ];
        yield 'Via Roma 15 interno 3' => [
            'Via Roma 15 interno 3', 'Via Roma 15 interno 3', [
                ['Via Roma 15 interno 3', null, ''],
                ['Via Roma', '15', 'interno 3'],
                ['Via Roma 15 interno', '3', ''],
            ],
        ];
        yield 'Via Roma 15 A interno 3' => [
            'Via Roma 15 A interno 3', 'Via Roma 15 A interno 3', [
                ['Via Roma 15 A interno 3', null, ''],
                ['Via Roma', '15', 'A interno 3'],
                ['Via Roma 15 A interno', '3', ''],
            ],
        ];
        yield 'Via Roma 15 - int. 3' => [
            'Via Roma 15 - int. 3', 'Via Roma 15 - int. 3', [
                ['Via Roma 15 - int. 3', null, ''],
                ['Via Roma', '15', '- int. 3'],
                ['Via Roma 15 - int.', '3', ''],
            ],
        ];
        yield 'Via Roma, 15' => [
            'Via Roma, 15', 'Via Roma 15', [
                ['Via Roma 15', null, ''],
                ['Via Roma', '15', ''],
            ],
        ];
        yield 'Via Roma n. 15' => [
            'Via Roma n. 15', 'Via Roma 15', [
                ['Via Roma 15', null, ''],
                ['Via Roma', '15', ''],
            ],
        ];
        yield 'Via Roma n° 15' => [
            'Via Roma n° 15', 'Via Roma 15', [
                ['Via Roma 15', null, ''],
                ['Via Roma', '15', ''],
            ],
        ];
        yield 'Via Roma N 15' => [
            'Via Roma N 15', 'Via Roma 15', [
                ['Via Roma 15', null, ''],
                ['Via Roma', '15', ''],
            ],
        ];
        yield 'Via Roma num. 15' => [
            'Via Roma num. 15', 'Via Roma 15', [
                ['Via Roma 15', null, ''],
                ['Via Roma', '15', ''],
            ],
        ];
        yield 'Via Roma numero 15' => [
            'Via Roma numero 15', 'Via Roma 15', [
                ['Via Roma 15', null, ''],
                ['Via Roma', '15', ''],
            ],
        ];
        yield 'Via Roma SNC' => [
            'Via Roma SNC', 'Via Roma SNC', [
                ['Via Roma', null, ''],
            ], true,
        ];
        yield 'Via Roma' => [
            'Via Roma', 'Via Roma', [
                ['Via Roma', null, ''],
            ],
        ];
        yield 'Via 20 Settembre' => [
            'Via 20 Settembre', 'Via 20 Settembre', [
                ['Via 20 Settembre', null, ''],
                ['Via', '20', 'Settembre'],
            ],
        ];
        yield 'Via 8 Luglio' => [
            'Via 8 Luglio', 'Via 8 Luglio', [
                ['Via 8 Luglio', null, ''],
                ['Via', '8', 'Luglio'],
            ],
        ];
        yield 'Via 8 Luglio 15' => [
            'Via 8 Luglio 15', 'Via 8 Luglio 15', [
                ['Via 8 Luglio 15', null, ''],
                ['Via', '8', 'Luglio 15'],
                ['Via 8 Luglio', '15', ''],
            ],
        ];
        yield 'Via 8 Luglio 15 A' => [
            'Via 8 Luglio 15 A', 'Via 8 Luglio 15 A', [
                ['Via 8 Luglio 15 A', null, ''],
                ['Via', '8', 'Luglio 15 A'],
                ['Via 8 Luglio', '15', 'A'],
            ],
        ];
        yield 'Strada Statale 16' => [
            'Strada Statale 16', 'Strada Statale 16', [
                ['Strada Statale 16', null, ''],
                ['Strada Statale', '16', ''],
            ],
        ];
        yield 'Strada Statale 16 25' => [
            'Strada Statale 16 25', 'Strada Statale 16 25', [
                ['Strada Statale 16 25', null, ''],
                ['Strada Statale', '16', '25'],
                ['Strada Statale 16', '25', ''],
            ],
        ];
        yield 'Via 11 Settembre 2001' => [
            'Via 11 Settembre 2001', 'Via 11 Settembre 2001', [
                ['Via 11 Settembre 2001', null, ''],
                ['Via', '11', 'Settembre 2001'],
                ['Via 11 Settembre', '2001', ''],
            ],
        ];
        yield 'Via 11 Settembre 2001 25' => [
            'Via 11 Settembre 2001 25', 'Via 11 Settembre 2001 25', [
                ['Via 11 Settembre 2001 25', null, ''],
                ['Via', '11', 'Settembre 2001 25'],
                ['Via 11 Settembre', '2001', '25'],
                ['Via 11 Settembre 2001', '25', ''],
            ],
        ];
    }

    public function testEmptyVianumHasNoCandidatesAndKeepsOptionalFields(): void
    {
        $input = new AddressInput('', null, null, null);
        $parsed = $this->parser->parse($input);

        self::assertSame([], $parsed->candidates);
        self::assertFalse($parsed->hasNoHouseNumber);
        self::assertSame($input, $parsed->input);
    }

    public function testCandidatesAreUniqueAndHouseNumberContainsOnlyDigits(): void
    {
        $parsed = $this->parser->parse($this->input('Via Roma 15/A 20'));
        $signatures = $this->candidateSignatures($parsed->candidates);

        self::assertCount(count(array_unique(array_map('serialize', $signatures))), $signatures);
        foreach ($parsed->candidates as $candidate) {
            if ($candidate->houseNumber !== null) {
                self::assertMatchesRegularExpression('/^\\d+$/', $candidate->houseNumber->number);
            }
        }
    }

    public function testCandidateAndHouseNumberAreReadonlyValueObjects(): void
    {
        self::assertTrue((new \ReflectionClass(AddressCandidate::class))->isReadOnly());
        self::assertTrue((new \ReflectionClass(HouseNumber::class))->isReadOnly());
        self::assertTrue((new \ReflectionClass(ParsedAddress::class))->isReadOnly());
    }

    /**
     * @param list<AddressCandidate> $candidates
     * @return list<array{string, ?string, string}>
     */
    private function candidateSignatures(array $candidates): array
    {
        $signatures = array_map(
            static fn (AddressCandidate $candidate): array => [
                $candidate->streetName,
                $candidate->houseNumber?->number,
                $candidate->trailingInformation,
            ],
            $candidates,
        );

        return $this->sortSignatures($signatures);
    }

    /**
     * @param list<array{string, ?string, string}> $signatures
     * @return list<array{string, ?string, string}>
     */
    private function sortSignatures(array $signatures): array
    {
        usort($signatures, static fn (array $a, array $b): int => serialize($a) <=> serialize($b));

        return $signatures;
    }

    private function input(string $vianum): AddressInput
    {
        return new AddressInput($vianum, null, null, null);
    }
}
