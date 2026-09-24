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

    #[DataProvider('addressExamples')]
    public function testParsesCivicNumberAndPreservesAllFollowingInformation(
        string $vianum,
        string $street,
        ?string $number,
        string $trailing,
        bool $hasNoHouseNumber = false,
    ): void {
        $parsed = $this->parser->parse($this->input($vianum));

        self::assertSame($street, $parsed->streetName);
        self::assertSame($number, $parsed->houseNumber?->number);
        self::assertSame($trailing, $parsed->trailingInformation);
        self::assertSame($hasNoHouseNumber, $parsed->hasNoHouseNumber);
        self::assertSame($vianum, $parsed->input->vianum);
    }

    public static function addressExamples(): iterable
    {
        yield 'plain civic number' => ['Via Roma 15', 'Via Roma', '15', ''];
        yield 'space followed by letter' => ['Via Roma 15 A', 'Via Roma', '15', 'A'];
        yield 'slash detail' => ['Via Roma 15/A', 'Via Roma', '15', '/A'];
        yield 'attached letter detail' => ['Via Roma 15A', 'Via Roma', '15', 'A'];
        yield 'color detail' => ['Via Roma 15 Rosso', 'Via Roma', '15', 'Rosso'];
        yield 'interior detail with number' => ['Via Roma 15 interno 3', 'Via Roma', '15', 'interno 3'];
        yield 'letter and interior detail' => ['Via Roma 15 A interno 3', 'Via Roma', '15', 'A interno 3'];
        yield 'punctuated detail' => ['Via Roma 15 - int. 3', 'Via Roma', '15', '- int. 3'];
        yield 'comma detail' => ['Via Roma 15, interno 3', 'Via Roma', '15', ', interno 3'];
        yield 'comma before civic number' => ['Via Roma, 15', 'Via Roma', '15', ''];
        yield 'n dot introducer' => ['Via Roma n. 15', 'Via Roma', '15', ''];
        yield 'n degree introducer' => ['Via Roma n° 15', 'Via Roma', '15', ''];
        yield 'n introducer' => ['Via Roma N 15', 'Via Roma', '15', ''];
        yield 'num dot introducer' => ['Via Roma num. 15', 'Via Roma', '15', ''];
        yield 'numero introducer' => ['Via Roma numero 15', 'Via Roma', '15', ''];
        yield 'slash numeric detail' => ['Via Roma 15/17', 'Via Roma', '15', '/17'];
        yield 'hyphenated numeric detail' => ['Via Roma 15-17', 'Via Roma', '15', '-17'];
        yield 'explicit no civic number' => ['Via Roma SNC', 'Via Roma', null, '', true];
        yield 'street without civic number' => ['Via Roma', 'Via Roma', null, ''];
        yield 'number in street name' => ['Via 20 Settembre', 'Via 20 Settembre', null, ''];
        yield 'number in street name without civic' => ['Via 8 Luglio', 'Via 8 Luglio', null, ''];
        yield 'street number followed by civic' => ['Via 8 Luglio 15', 'Via 8 Luglio', '15', ''];
        yield 'street number followed by civic and detail' => ['Via 8 Luglio 15 A', 'Via 8 Luglio', '15', 'A'];
    }

    public function testTrimsAddressEdgesButPreservesOriginalInputAndDetailText(): void
    {
        $input = $this->input('  Via   Roma 15   interno 3  ');
        $parsed = $this->parser->parse($input);

        self::assertSame('Via   Roma', $parsed->streetName);
        self::assertSame('interno 3  ', $parsed->trailingInformation);
        self::assertSame('  Via   Roma 15   interno 3  ', $parsed->input->vianum);
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
        $nullFields = new AddressInput('', null, null, null);

        $parsedWithEmptyCap = $this->parser->parse($emptyCap);
        $parsedWithNullFields = $this->parser->parse($nullFields);

        self::assertSame('', $parsedWithEmptyCap->input->cap);
        self::assertSame('Città', $parsedWithEmptyCap->input->city);
        self::assertSame('rm', $parsedWithEmptyCap->input->province);
        self::assertNull($parsedWithNullFields->input->cap);
        self::assertNull($parsedWithNullFields->input->city);
        self::assertNull($parsedWithNullFields->input->province);
    }

    public function testPreservesCapLeadingZeroesCityAndProvinceExactly(): void
    {
        $input = new AddressInput('Via Roma 15', '00123', 'Città', 'rm');
        $parsed = $this->parser->parse($input);

        self::assertSame('00123', $parsed->input->cap);
        self::assertSame('Città', $parsed->input->city);
        self::assertSame('rm', $parsed->input->province);
    }

    private function input(string $vianum): AddressInput
    {
        return new AddressInput($vianum, null, null, null);
    }
}
