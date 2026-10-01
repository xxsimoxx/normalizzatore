<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Address;

use Normalizzatore\Address\AddressCandidate;
use Normalizzatore\Address\AddressInput;
use Normalizzatore\Address\AddressParser;
use Normalizzatore\Address\AddressSyntaxPreferenceEvaluator;
use Normalizzatore\Address\AddressSyntaxPreferenceReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddressSyntaxPreferenceEvaluatorTest extends TestCase
{
    #[DataProvider('preferredExamples')]
    public function testPrefersConservativeFinalCivicInterpretation(string $source, string $street, string $number, string $details, AddressSyntaxPreferenceReason $reason): void
    {
        $parsed = (new AddressParser())->parse(new AddressInput($source, '99999', 'Olbia', 'SS'));

        self::assertNotNull($parsed->syntaxPreference);
        self::assertSame($reason, $parsed->syntaxPreference->reason);
        self::assertNotNull($parsed->syntaxPreference->preferredCandidate);
        self::assertSame($street, $parsed->syntaxPreference->preferredCandidate->streetName);
        self::assertSame($number, $parsed->syntaxPreference->preferredCandidate->houseNumber?->number);
        self::assertSame($details, $parsed->syntaxPreference->preferredCandidate->trailingInformation);
        self::assertGreaterThanOrEqual(2, count($parsed->candidates));
        self::assertTrue(in_array($parsed->syntaxPreference->preferredCandidate, $parsed->candidates, true));
    }

    public static function preferredExamples(): iterable
    {
        yield 'ordinary final civic' => ['VIA ROMA 50', 'VIA ROMA', '50', '', AddressSyntaxPreferenceReason::FINAL_CIVIC_NUMBER];
        yield 'comma clue' => ['VIA FIRENZE, 27', 'VIA FIRENZE', '27', '', AddressSyntaxPreferenceReason::FINAL_CIVIC_AFTER_COMMA];
        yield 'street number preserved' => ['VIA 4 NOVEMBRE 15', 'VIA 4 NOVEMBRE', '15', '', AddressSyntaxPreferenceReason::FINAL_CIVIC_NUMBER];
        yield 'street number and civic letter' => ['VIA 8 LUGLIO 15 A', 'VIA 8 LUGLIO', '15', 'A', AddressSyntaxPreferenceReason::FINAL_CIVIC_WITH_SUFFIX];
        yield 'numbered state road' => ['STRADA STATALE 127, 111', 'STRADA STATALE 127', '111', '', AddressSyntaxPreferenceReason::FINAL_CIVIC_AFTER_NUMBERED_STATE_ROAD];
        yield 'numbered provincial road with BIS and comma' => ['STRADA PROVINCIALE 38 BIS, 55', 'STRADA PROVINCIALE 38 BIS', '55', '', AddressSyntaxPreferenceReason::FINAL_CIVIC_AFTER_COMMA];
        yield 'numbered date toponym with comma before civic' => ['VIA 1 MAGGIO, 20', 'VIA 1 MAGGIO', '20', '', AddressSyntaxPreferenceReason::FINAL_CIVIC_AFTER_COMMA];
        yield 'attached suffix' => ['VIA RAI 6A', 'VIA RAI', '6', 'A', AddressSyntaxPreferenceReason::FINAL_CIVIC_WITH_SUFFIX];
        yield 'spaced suffix' => ['VIA PRAGRANDE 23 A', 'VIA PRAGRANDE', '23', 'A', AddressSyntaxPreferenceReason::FINAL_CIVIC_WITH_SUFFIX];
    }

    #[DataProvider('unpreferredExamples')]
    public function testAbstainsWhenTheNumericBoundaryIsNotSafe(string $source, AddressSyntaxPreferenceReason $reason): void
    {
        $parsed = (new AddressParser())->parse(new AddressInput($source, null, 'Olbia', 'SS'));

        self::assertNotNull($parsed->syntaxPreference);
        self::assertNull($parsed->syntaxPreference->preferredCandidate);
        self::assertSame($reason, $parsed->syntaxPreference->reason);
    }

    public static function unpreferredExamples(): iterable
    {
        yield 'complex internal detail' => ['VIA DEL GRIFO 4/INT 8', AddressSyntaxPreferenceReason::COMPLEX_CIVIC_DETAILS];
        yield 'lettered civic then number' => ['VIA SARDEGNA 12/B 15', AddressSyntaxPreferenceReason::COMPLEX_CIVIC_DETAILS];
        yield 'no civic' => ['VIA ROMA', AddressSyntaxPreferenceReason::NO_CIVIC_NUMBER];
        yield 'explicit no civic' => ['VIA ROMA SNC', AddressSyntaxPreferenceReason::EXPLICIT_SNC];
        yield 'multiple numeric boundaries' => ['VIA 12 15', AddressSyntaxPreferenceReason::AMBIGUOUS_NUMERIC_BOUNDARY];
        yield 'long terminal number after a date name' => ['VIA 4 NOVEMBRE 1470', AddressSyntaxPreferenceReason::AMBIGUOUS_NUMERIC_BOUNDARY];
        yield 'unmarked numeric street phrase' => ['VIA CORER 1 RAMO 10', AddressSyntaxPreferenceReason::AMBIGUOUS_NUMERIC_BOUNDARY];
        yield 'multiple civic and detail numbers' => ['VIA CAPITELLO DI SOTTO 4/P S 1 T L 4', AddressSyntaxPreferenceReason::COMPLEX_CIVIC_DETAILS];
        yield 'complex abbreviated civic details' => ['VIALE MILANO 86/P 4 I 11', AddressSyntaxPreferenceReason::COMPLEX_CIVIC_DETAILS];
        yield 'terminal number follows internal marker' => ['VIA CADORE A3 INT. 3', AddressSyntaxPreferenceReason::COMPLEX_CIVIC_DETAILS];
        yield 'date without day' => ['VIA MAGGIO 1848 24', AddressSyntaxPreferenceReason::AMBIGUOUS_NUMERIC_BOUNDARY];
        yield 'date marker cannot cross slash' => ['VIA VICOLO 5/MAGGIO 1848 5', AddressSyntaxPreferenceReason::COMPLEX_CIVIC_DETAILS];
        yield 'abbreviated year is not a date' => ["VIA RAGAZZI DEL ' 99 5", AddressSyntaxPreferenceReason::AMBIGUOUS_NUMERIC_BOUNDARY];
        yield 'date civic exceeds conservative bound' => ['VIA 4 NOVEMBRE 1470', AddressSyntaxPreferenceReason::AMBIGUOUS_NUMERIC_BOUNDARY];
        yield 'day month with numeric detail is ambiguous' => ['VIA 18 GIUGNO 149/6', AddressSyntaxPreferenceReason::COMPLEX_CIVIC_DETAILS];
        yield 'date and complex internal tail' => ['VIA SETTEMBRE 1944 24/INT 2', AddressSyntaxPreferenceReason::COMPLEX_CIVIC_DETAILS];
        yield 'complex internal tail remains outside date rule' => ['VIA DEL GRIFO 4/INT 8', AddressSyntaxPreferenceReason::COMPLEX_CIVIC_DETAILS];
        yield 'slash suffix and following number remain complex' => ['VIA ENRICO TOTI 59/C 8', AddressSyntaxPreferenceReason::COMPLEX_CIVIC_DETAILS];
        yield 'lettered slash suffix and following number remain complex' => ['VIA SAN MAIOLO 5/P 1', AddressSyntaxPreferenceReason::COMPLEX_CIVIC_DETAILS];
        yield 'month and year without day remain ambiguous' => ['VIA NOVEMBRE 1918 23/15', AddressSyntaxPreferenceReason::COMPLEX_CIVIC_DETAILS];
        yield 'Arabic day outside calendar range' => ['VIA 32 MAGGIO 1944 6', AddressSyntaxPreferenceReason::COMPLEX_CIVIC_DETAILS];
        yield 'non-canonical Roman day' => ['VIA IIX MAGGIO 1944 6', AddressSyntaxPreferenceReason::AMBIGUOUS_NUMERIC_BOUNDARY];
        yield 'year above supported range' => ['VIA 21 OTTOBRE 2100 4', AddressSyntaxPreferenceReason::COMPLEX_CIVIC_DETAILS];
        yield 'year below supported range' => ['VIA 21 OTTOBRE 1799 4', AddressSyntaxPreferenceReason::COMPLEX_CIVIC_DETAILS];
    }

    #[DataProvider('streetDateExamples')]
    public function testPrefersExplicitDayMonthStreetDate(string $source, ?string $street, ?string $number, string $details): void
    {
        $parsed = (new AddressParser())->parse(new AddressInput($source, null, null, null));

        self::assertSame(AddressSyntaxPreferenceReason::NUMERIC_STREET_DATE, $parsed->syntaxPreference?->reason);
        self::assertNotNull($parsed->syntaxPreference?->preferredCandidate);
        self::assertSame($street, $parsed->syntaxPreference->preferredCandidate->streetName);
        self::assertSame($number, $parsed->syntaxPreference->preferredCandidate->houseNumber?->number);
        self::assertSame($details, $parsed->syntaxPreference->preferredCandidate->trailingInformation);
        self::assertContains($parsed->syntaxPreference->preferredCandidate, $parsed->candidates);
    }

    public static function streetDateExamples(): iterable
    {
        yield 'Arabic day and month without civic' => ['VIA 11 SETTEMBRE', 'VIA 11 SETTEMBRE', null, ''];
        yield 'day and month without civic' => ['VIA 19 LUGLIO', 'VIA 19 LUGLIO', null, ''];
        yield 'square day and month without civic' => ['PIAZZA 24 MAGGIO', 'PIAZZA 24 MAGGIO', null, ''];
        yield 'Roman day month year and civic' => ['VIA XXVII APRILE 1945 43', 'VIA XXVII APRILE 1945', '43', ''];
        yield 'Arabic day month year and civic' => ['VIA 21 OTTOBRE 1866 4', 'VIA 21 OTTOBRE 1866', '4', ''];
        yield 'Roman day month year and suffixed civic' => ['VIA XIV MAGGIO 1944 6/B', 'VIA XIV MAGGIO 1944', '6', '/B'];
        yield 'Arabic day month year and civic in Viale' => ['VIALE 14 AGOSTO 1866 31', 'VIALE 14 AGOSTO 1866', '31', ''];
    }

    public function testDatePreferenceLeavesParserCandidateSetAndOrderUnchanged(): void
    {
        $parsed = (new AddressParser())->parse(new AddressInput('VIA XXVII APRILE 1945 43', null, null, null));

        self::assertCount(3, $parsed->candidates);
        self::assertSame('VIA XXVII APRILE 1945 43', $parsed->candidates[0]->streetName);
        self::assertSame('VIA XXVII APRILE', $parsed->candidates[1]->streetName);
        self::assertSame('1945', $parsed->candidates[1]->houseNumber?->number);
        self::assertSame('VIA XXVII APRILE 1945', $parsed->candidates[2]->streetName);
        self::assertSame('43', $parsed->candidates[2]->houseNumber?->number);
        self::assertSame($parsed->candidates[2], $parsed->syntaxPreference?->preferredCandidate);
    }

    #[DataProvider('stableDateRelatedExamples')]
    public function testExistingDateRelatedPreferencesRemainUnchanged(string $source, AddressSyntaxPreferenceReason $reason, string $street, string $number, string $details): void
    {
        $parsed = (new AddressParser())->parse(new AddressInput($source, null, null, null));

        self::assertSame($reason, $parsed->syntaxPreference?->reason);
        self::assertSame($street, $parsed->syntaxPreference?->preferredCandidate?->streetName);
        self::assertSame($number, $parsed->syntaxPreference?->preferredCandidate?->houseNumber?->number);
        self::assertSame($details, $parsed->syntaxPreference?->preferredCandidate?->trailingInformation);
    }

    public static function stableDateRelatedExamples(): iterable
    {
        yield 'four November with suffix' => ['VIA 4 NOVEMBRE 14 A', AddressSyntaxPreferenceReason::FINAL_CIVIC_WITH_SUFFIX, 'VIA 4 NOVEMBRE', '14', 'A'];
        yield 'twenty fifth April' => ['VIA XXV APRILE 146', AddressSyntaxPreferenceReason::FINAL_CIVIC_NUMBER, 'VIA XXV APRILE', '146', ''];
        yield 'second June' => ['VIA 2 GIUGNO 25', AddressSyntaxPreferenceReason::FINAL_CIVIC_NUMBER, 'VIA 2 GIUGNO', '25', ''];
        yield 'twenty seventh April' => ['VIA XXVII APRILE 21', AddressSyntaxPreferenceReason::FINAL_CIVIC_NUMBER, 'VIA XXVII APRILE', '21', ''];
        yield 'fourth November' => ['VIA IV NOVEMBRE 15', AddressSyntaxPreferenceReason::FINAL_CIVIC_NUMBER, 'VIA IV NOVEMBRE', '15', ''];
        yield 'first May' => ['VIA PRIMO MAGGIO 12', AddressSyntaxPreferenceReason::FINAL_CIVIC_NUMBER, 'VIA PRIMO MAGGIO', '12', ''];
        yield 'Roro slash number' => ['VIA RORO 2/2', AddressSyntaxPreferenceReason::FINAL_CIVIC_NUMBER, 'VIA RORO', '2', '/2'];
        yield 'Roman fifth May' => ['VIA V MAGGIO 32/C', AddressSyntaxPreferenceReason::FINAL_CIVIC_WITH_SUFFIX, 'VIA V MAGGIO', '32', '/C'];
        yield 'twenty fifth April with civic suffix' => ['PIAZZA XXV APRILE 26 B', AddressSyntaxPreferenceReason::FINAL_CIVIC_WITH_SUFFIX, 'PIAZZA XXV APRILE', '26', 'B'];
        yield 'four November civic fifty five' => ['VIA 4 NOVEMBRE 55', AddressSyntaxPreferenceReason::FINAL_CIVIC_NUMBER, 'VIA 4 NOVEMBRE', '55', ''];
    }

    public function testCandidateOrderDoesNotDeterminePreferenceAndNoCandidateIsRemoved(): void
    {
        $parser = new AddressParser();
        $parsed = $parser->parse(new AddressInput('VIA ROMA 50', null, null, null));
        $preference = (new AddressSyntaxPreferenceEvaluator())->evaluate(
            'VIA ROMA 50',
            array_reverse($parsed->candidates),
        );

        self::assertSame('VIA ROMA', $preference->preferredCandidate?->streetName);
        self::assertSame('50', $preference->preferredCandidate?->houseNumber?->number);
        self::assertCount(2, $parsed->candidates);
        self::assertSame('VIA ROMA 50', $parsed->candidates[0]->streetName);
        self::assertSame('VIA ROMA', $parsed->candidates[1]->streetName);
        self::assertSame($parsed->syntaxPreference?->preferredCandidate?->streetName, $preference->preferredCandidate?->streetName);
        self::assertSame($parsed->syntaxPreference?->preferredCandidate?->houseNumber?->number, $preference->preferredCandidate?->houseNumber?->number);
    }

    public function testPreferenceIgnoresSourceCapAndPreservesIrregularSpacingAndPunctuation(): void
    {
        $parser = new AddressParser();
        $first = $parser->parse(new AddressInput('VIA  8 LUGLIO, 15 A', '00100', 'Cagliari', 'CA'));
        $second = $parser->parse(new AddressInput('VIA  8 LUGLIO, 15 A', '99999', 'Olbia', 'SS'));

        self::assertSame('VIA  8 LUGLIO', $first->syntaxPreference?->preferredCandidate?->streetName);
        self::assertSame('15', $first->syntaxPreference?->preferredCandidate?->houseNumber?->number);
        self::assertSame('A', $first->syntaxPreference?->preferredCandidate?->trailingInformation);
        self::assertSame($first->syntaxPreference?->reason, $second->syntaxPreference?->reason);
        self::assertSame($first->syntaxPreference?->preferredCandidate?->streetName, $second->syntaxPreference?->preferredCandidate?->streetName);
        self::assertCount(3, $first->candidates);
        self::assertSame('VIA  8 LUGLIO, 15 A', $first->input->vianum);
    }

    public function testEmptyAddressHasAnExplicitNoCandidateReason(): void
    {
        $parsed = (new AddressParser())->parse(new AddressInput('', null, null, null));

        self::assertSame([], $parsed->candidates);
        self::assertSame(AddressSyntaxPreferenceReason::NO_CANDIDATES, $parsed->syntaxPreference?->reason);
        self::assertNull($parsed->syntaxPreference?->preferredCandidate);
    }

    public function testEquivalentCandidatesDoNotProduceAnArbitraryPreference(): void
    {
        $candidateA = new AddressCandidate('VIA ROMA', null, '');
        $candidateB = new AddressCandidate('VIA ROMA', null, '');
        $preference = (new AddressSyntaxPreferenceEvaluator())->evaluate('VIA ROMA', [$candidateA, $candidateB]);

        self::assertNull($preference->preferredCandidate);
        self::assertSame(AddressSyntaxPreferenceReason::EQUIVALENT_CANDIDATES, $preference->reason);
    }
}
