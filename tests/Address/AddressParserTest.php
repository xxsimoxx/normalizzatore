<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Address;

use Normalizzatore\Address\AddressInput;
use Normalizzatore\Address\AddressParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddressParserTest extends TestCase
{
    private AddressParser $parser;

    protected function setUp(): void
    {
        $this->parser = new AddressParser();
    }

    #[DataProvider('civicNumberExamples')]
    public function testParsesSupportedCivicNumberForms(
        string $vianum,
        string $street,
        string $number,
        ?string $separator,
        ?string $suffix,
        ?string $rangeEnd,
    ): void {
        $parsed = $this->parser->parse($this->input($vianum));

        self::assertSame($street, $parsed->streetName);
        self::assertNotNull($parsed->houseNumber);
        self::assertSame($number, $parsed->houseNumber->number);
        self::assertSame($separator, $parsed->houseNumber->separator);
        self::assertSame($suffix, $parsed->houseNumber->suffix);
        self::assertSame($rangeEnd, $parsed->houseNumber->rangeEnd);
        self::assertFalse($parsed->hasNoHouseNumber);
    }

    public static function civicNumberExamples(): iterable
    {
        yield 'plain number' => ['Via Roma 15', 'Via Roma', '15', null, null, null];
        yield 'slash suffix' => ['Via Roma 15/A', 'Via Roma', '15', '/', 'A', null];
        yield 'space suffix' => ['Via Roma 15 A', 'Via Roma', '15', ' ', 'A', null];
        yield 'number range' => ['Via Roma 15-17', 'Via Roma', '15', '-', null, '17'];
    }

    public function testPreservesTextFollowingCivicNumber(): void
    {
        $parsed = $this->parser->parse($this->input('Via Roma 15 interno 3'));

        self::assertSame('Via Roma', $parsed->streetName);
        self::assertSame('15', $parsed->houseNumber?->raw);
        self::assertSame('interno 3', $parsed->trailingInformation);
        self::assertSame('Via Roma 15 interno 3', $parsed->input->vianum);
    }

    public function testSncMeansExplicitlyWithoutHouseNumber(): void
    {
        $parsed = $this->parser->parse($this->input('Via Roma SNC'));

        self::assertSame('Via Roma', $parsed->streetName);
        self::assertNull($parsed->houseNumber);
        self::assertTrue($parsed->hasNoHouseNumber);
    }

    public function testAddressWithoutCivicNumberIsUnknownRatherThanExplicitlyNoNumber(): void
    {
        $parsed = $this->parser->parse($this->input('Via Roma'));

        self::assertSame('Via Roma', $parsed->streetName);
        self::assertNull($parsed->houseNumber);
        self::assertFalse($parsed->hasNoHouseNumber);
    }

    public function testTrimsSurroundingAndRepeatedWhitespaceWithoutChangingRawInput(): void
    {
        $input = $this->input('  Via   Roma   15   ');
        $parsed = $this->parser->parse($input);

        self::assertSame('Via   Roma', $parsed->streetName);
        self::assertSame('15', $parsed->houseNumber?->raw);
        self::assertSame('  Via   Roma   15   ', $parsed->input->vianum);
    }

    public function testEmptyVianumHasNoHouseNumber(): void
    {
        $parsed = $this->parser->parse($this->input(''));

        self::assertSame('', $parsed->streetName);
        self::assertNull($parsed->houseNumber);
        self::assertFalse($parsed->hasNoHouseNumber);
    }

    public function testPreservesNullAndEmptyCapAndOtherRawAddressFields(): void
    {
        $emptyCap = new AddressInput('Via Roma', '', 'Città', 'rm');
        $nullCap = new AddressInput('Via Roma', null, null, null);

        self::assertSame('', $this->parser->parse($emptyCap)->input->cap);
        self::assertSame('Città', $this->parser->parse($emptyCap)->input->city);
        self::assertSame('rm', $this->parser->parse($emptyCap)->input->province);
        self::assertNull($this->parser->parse($nullCap)->input->cap);
    }

    public function testPreservesCapLeadingZeroesCityAndProvinceExactly(): void
    {
        $input = new AddressInput('Via Roma 15', '00123', 'Città', 'rm');
        $parsed = $this->parser->parse($input);

        self::assertSame('00123', $parsed->input->cap);
        self::assertSame('Città', $parsed->input->city);
        self::assertSame('rm', $parsed->input->province);
    }

    public function testDoesNotTreatDigitsEmbeddedInStreetNameAsHouseNumber(): void
    {
        $parsed = $this->parser->parse($this->input('Via 20 Settembre'));

        self::assertSame('Via 20 Settembre', $parsed->streetName);
        self::assertNull($parsed->houseNumber);
    }

    public function testDistinguishesStreetNumberFromCivicNumberAndPreservesSingleLetterTail(): void
    {
        $streetOnly = $this->parser->parse($this->input('Via 8 Luglio'));
        $withCivicNumber = $this->parser->parse($this->input('Via 8 Luglio 15'));
        $withTrailingLetter = $this->parser->parse($this->input('Via 8 Luglio 15 A'));

        self::assertSame('Via 8 Luglio', $streetOnly->streetName);
        self::assertNull($streetOnly->houseNumber);

        self::assertSame('Via 8 Luglio', $withCivicNumber->streetName);
        self::assertSame('15', $withCivicNumber->houseNumber?->number);
        self::assertSame('', $withCivicNumber->trailingInformation);

        self::assertSame('Via 8 Luglio', $withTrailingLetter->streetName);
        self::assertSame('15', $withTrailingLetter->houseNumber?->number);
        self::assertNull($withTrailingLetter->houseNumber?->suffix);
        self::assertSame('A', $withTrailingLetter->trailingInformation);
    }

    #[DataProvider('ambiguousCivicNumberExamples')]
    public function testLeavesMalformedOrAmbiguousNumberLikeTextUnparsed(string $vianum): void
    {
        $parsed = $this->parser->parse($this->input($vianum));

        self::assertSame($vianum, $parsed->streetName);
        self::assertNull($parsed->houseNumber);
    }

    public static function ambiguousCivicNumberExamples(): iterable
    {
        yield 'number embedded before street word' => ['Via 15 Roma'];
        yield 'multiple numbers with unclear range syntax' => ['Via Roma 15/17/A'];
        yield 'text after number without a recognized detail marker' => ['Via Roma 15 Rosso'];
    }

    private function input(string $vianum): AddressInput
    {
        return new AddressInput($vianum, null, null, null);
    }
}
