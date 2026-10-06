<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Resolution;

use InvalidArgumentException;
use Normalizzatore\Address\StreetNameTokenizer;
use Normalizzatore\Resolution\FuzzyStreetDiagnostic;
use Normalizzatore\Resolution\FuzzyStreetMatchKind;
use Normalizzatore\Resolution\FuzzyStreetMatchingOptions;
use Normalizzatore\Resolution\FuzzyStreetNameCandidate;
use Normalizzatore\Resolution\FuzzyStreetResolutionStatus;
use Normalizzatore\Resolution\TypoStreetMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TypoStreetMatcherTest extends TestCase
{
    private StreetNameTokenizer $tokenizer;

    protected function setUp(): void
    {
        $this->tokenizer = new StreetNameTokenizer();
    }

    #[DataProvider('positiveExamples')]
    public function testMatchesOneNominalTokenTypo(string $sourceName, string $candidateName, int $position): void
    {
        $result = (new TypoStreetMatcher())->match($this->street($sourceName), [$this->candidate($candidateName)]);

        self::assertSame(FuzzyStreetResolutionStatus::MATCH, $result->status);
        self::assertSame(FuzzyStreetMatchKind::TYPO, $result->match?->kind);
        self::assertSame($candidateName, $result->match?->candidate->canonicalName);
        self::assertSame($position, $result->match?->tokenPosition);
        self::assertSame(1, $result->match?->distance);
        self::assertNull($result->match?->secondBestDistance);
        self::assertNull($result->match?->margin);
    }

    public static function positiveExamples(): iterable
    {
        yield 'doubled consonant' => ['VIA CAPUCCINA', 'VIA CAPPUCCINA', 0];
        yield 'extra letter' => ['VIA GUIDO GUINIZZELLI', 'VIA GUIDO GUINIZELLI', 1];
        yield 'substitution' => ['VIA LEONE TOLSTOI', 'VIA LEONE TOLSTOJ', 1];
        yield 'adjacent transposition' => ['VIA GARIBLADI', 'VIA GARIBALDI', 0];
    }

    public function testTieIsAmbiguousAndIndependentOfCandidateOrder(): void
    {
        $source = $this->street('VIA GATTA');
        $gaeta = $this->candidate('VIA GAETA');
        $zatta = $this->candidate('VIA ZATTA');

        foreach ([[$gaeta, $zatta], [$zatta, $gaeta]] as $candidates) {
            $result = (new TypoStreetMatcher())->match($source, $candidates);

            self::assertSame(FuzzyStreetResolutionStatus::AMBIGUOUS, $result->status);
            self::assertNull($result->match);
            self::assertSame([FuzzyStreetDiagnostic::BEST_DISTANCE_TIE], $result->diagnostics);
            self::assertSame(['VIA GAETA', 'VIA ZATTA'], array_map(
                static fn ($evidence): string => $evidence->candidate->canonicalName,
                $result->ambiguousCandidates,
            ));
        }
    }

    public function testRequiresConfiguredMarginAndUsesSecondCandidateBeyondDistanceLimit(): void
    {
        $matcher = new TypoStreetMatcher();
        $source = $this->street('VIA ALFA');
        $best = $this->candidate('VIA ALFB');

        $smallMargin = $matcher->match($source, [$best, $this->candidate('VIA ALXY')]);
        self::assertSame(FuzzyStreetResolutionStatus::AMBIGUOUS, $smallMargin->status);
        self::assertSame(2, $smallMargin->ambiguousCandidates[0]->secondBestDistance);
        self::assertSame(1, $smallMargin->ambiguousCandidates[0]->margin);
        self::assertSame([FuzzyStreetDiagnostic::DISTANCE_MARGIN_TOO_SMALL], $smallMargin->diagnostics);

        $sufficientMargin = $matcher->match($source, [$best, $this->candidate('VIA ABXY')]);
        self::assertSame(FuzzyStreetResolutionStatus::MATCH, $sufficientMargin->status);
        self::assertSame(3, $sufficientMargin->match?->secondBestDistance);
        self::assertSame(2, $sufficientMargin->match?->margin);
    }

    public function testExactCandidateNeverBecomesTypoMatch(): void
    {
        $source = $this->street('VIA CAPPUCCINA');
        $result = (new TypoStreetMatcher())->match($source, [$this->candidate('VIA CAPPUCCINA'), $this->candidate('VIA CAPUCCINA')]);

        self::assertSame(FuzzyStreetResolutionStatus::NOT_APPLICABLE, $result->status);
    }

    public function testRejectsShortTokensAndFunctionalTokenTypos(): void
    {
        foreach ([['A', 'B'], ['AB', 'AC'], ['ABC', 'ABD']] as [$sourceToken, $candidateToken]) {
            $result = (new TypoStreetMatcher())->match(
                $this->street('VIA ' . $sourceToken),
                [$this->candidate('VIA ' . $candidateToken)],
            );
            self::assertSame(FuzzyStreetResolutionStatus::NOT_APPLICABLE, $result->status);
        }

        $functional = (new TypoStreetMatcher())->match(
            $this->street('VIA DEL GARIBALDI'),
            [$this->candidate('VIA DEI GARIBALDI')],
        );
        self::assertSame(FuzzyStreetResolutionStatus::NOT_APPLICABLE, $functional->status);

        $lengthFour = (new TypoStreetMatcher())->match(
            $this->street('VIA ABCD'),
            [$this->candidate('VIA ABCE')],
        );
        self::assertSame(FuzzyStreetResolutionStatus::MATCH, $lengthFour->status);
    }

    public function testRejectsDifferentTypeTokenCountMultipleTyposAndCombinedAbbreviationTypo(): void
    {
        $matcher = new TypoStreetMatcher();
        $cases = [
            ['VIA ENRICO FERMI', ['VIALE ENRICO FERMI']],
            ['VIA MONTEGRAPPA', ['VIA MONTE GRAPPA']],
            ['VIA ENRCO FRMI', ['VIA ENRICO FERMI']],
            ['VIA E. FRMI', ['VIA ENRICO FERMI']],
        ];
        foreach ($cases as [$sourceName, $candidateNames]) {
            self::assertSame(
                FuzzyStreetResolutionStatus::NOT_APPLICABLE,
                $matcher->match($this->street($sourceName), array_map($this->candidate(...), $candidateNames))->status,
                $sourceName,
            );
        }
    }

    public function testReturnsNoMatchWhenTheBestStructurallyEligibleCandidateExceedsMaximumDistance(): void
    {
        $result = (new TypoStreetMatcher())->match(
            $this->street('VIA ABCD'),
            [$this->candidate('VIA WXYZ')],
        );

        self::assertSame(FuzzyStreetResolutionStatus::NO_MATCH, $result->status);
    }

    public function testAllowsAlternatePolicyThroughImmutableOptions(): void
    {
        $options = new FuzzyStreetMatchingOptions(4, 2, 1);
        $result = (new TypoStreetMatcher($options))->match(
            $this->street('VIA ABCD'),
            [$this->candidate('VIA ABXY')],
        );

        self::assertSame(FuzzyStreetResolutionStatus::MATCH, $result->status);
        self::assertSame(2, $result->match?->distance);
        self::assertSame(0.5, $result->match?->normalizedDistance);
    }

    public function testRejectsInvalidOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FuzzyStreetMatchingOptions(0, 1, 2);
    }

    private function street(string $name): \Normalizzatore\Address\TokenizedStreetName
    {
        return $this->tokenizer->tokenize($name) ?? self::fail('Expected a supported street name.');
    }

    private function candidate(string $name): FuzzyStreetNameCandidate
    {
        return new FuzzyStreetNameCandidate($this->street($name));
    }
}
