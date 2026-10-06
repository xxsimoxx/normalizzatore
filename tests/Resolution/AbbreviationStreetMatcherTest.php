<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Resolution;

use Normalizzatore\Address\StreetNameTokenizer;
use Normalizzatore\Resolution\AbbreviationStreetMatcher;
use Normalizzatore\Resolution\FuzzyStreetMatchKind;
use Normalizzatore\Resolution\FuzzyStreetNameCandidate;
use Normalizzatore\Resolution\FuzzyStreetResolutionStatus;
use PHPUnit\Framework\TestCase;

final class AbbreviationStreetMatcherTest extends TestCase
{
    private StreetNameTokenizer $tokenizer;
    private AbbreviationStreetMatcher $matcher;

    protected function setUp(): void
    {
        $this->tokenizer = new StreetNameTokenizer();
        $this->matcher = new AbbreviationStreetMatcher();
    }

    public function testExpandsOneDottedInitialAtBeginningAndInteriorPositions(): void
    {
        foreach ([
            ['VIA E. FERMI', 'VIA ENRICO FERMI', 0],
            ['VIA G. GARIBALDI', 'VIA GIUSEPPE GARIBALDI', 0],
            ['VIA CARLO A. DALLA CHIESA', 'VIA CARLO ALBERTO DALLA CHIESA', 1],
        ] as [$sourceName, $candidateName, $position]) {
            $result = $this->matcher->match(
                $this->street($sourceName),
                [$this->candidate($candidateName)],
            );

            self::assertSame(FuzzyStreetResolutionStatus::MATCH, $result->status);
            self::assertSame(FuzzyStreetMatchKind::ABBREVIATION, $result->match?->kind);
            self::assertSame($position, $result->match?->tokenPosition);
            self::assertNull($result->match?->distance);
            self::assertSame($candidateName, $result->match?->candidate->canonicalName);
        }
    }

    public function testAmbiguousExpansionsRemainAmbiguousRegardlessOfCandidateOrder(): void
    {
        $source = $this->street('VIA E. FERMI');
        $enrico = $this->candidate('VIA ENRICO FERMI');
        $edoardo = $this->candidate('VIA EDOARDO FERMI');

        foreach ([[$enrico, $edoardo], [$edoardo, $enrico]] as $candidates) {
            $result = $this->matcher->match($source, $candidates);

            self::assertSame(FuzzyStreetResolutionStatus::AMBIGUOUS, $result->status);
            self::assertNull($result->match);
            self::assertSame(['VIA EDOARDO FERMI', 'VIA ENRICO FERMI'], array_map(
                static fn ($evidence): string => $evidence->candidate->canonicalName,
                $result->ambiguousCandidates,
            ));
        }
    }

    public function testDoesNotExpandUnsupportedOrStructurallyDifferentForms(): void
    {
        $cases = [
            ['VIA E FERMI', ['VIA ENRICO FERMI']],
            ['VIA G. B. VICO', ['VIA GIOVANNI BATTISTA VICO']],
            ['VIA G.B. VICO', ['VIA GIAMBATTISTA VICO']],
            ['VIA D. VITTORIO', ['VIA DI VITTORIO']],
            ['VIA E. FERMI', ['VIALE ENRICO FERMI']],
            ['VIA E. FERMI', ['VIA ENRICO CARLO FERMI']],
            ['VIA E. FERMI', ['VIA ENRICO ROSSI']],
        ];

        foreach ($cases as [$sourceName, $candidateNames]) {
            $result = $this->matcher->match($this->street($sourceName), array_map($this->candidate(...), $candidateNames));
            self::assertContains($result->status, [FuzzyStreetResolutionStatus::NOT_APPLICABLE, FuzzyStreetResolutionStatus::NO_MATCH], $sourceName);
        }
    }

    public function testExactCandidateIsNeverReturnedAsFuzzyMatch(): void
    {
        $source = $this->street('VIA E. FERMI');
        $result = $this->matcher->match($source, [$this->candidate('VIA E. FERMI'), $this->candidate('VIA ENRICO FERMI')]);

        self::assertSame(FuzzyStreetResolutionStatus::NOT_APPLICABLE, $result->status);
    }

    public function testRejectsAssociativeCandidateArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->matcher->match($this->street('VIA E. FERMI'), ['candidate' => $this->candidate('VIA ENRICO FERMI')]);
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
