<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Resolution;

use Normalizzatore\Address\StreetNameTokenizer;
use Normalizzatore\Resolution\FuzzyStreetMatcher;
use Normalizzatore\Resolution\FuzzyStreetNameCandidate;
use Normalizzatore\Resolution\FuzzyStreetResolutionStatus;
use PHPUnit\Framework\TestCase;

final class FuzzyStreetMatcherTest extends TestCase
{
    public function testAbbreviationAmbiguityStopsBeforeTypoMatching(): void
    {
        $tokenizer = new StreetNameTokenizer();
        $matcher = new FuzzyStreetMatcher();
        $source = $tokenizer->tokenize('VIA E. FERMI');
        self::assertNotNull($source);

        $result = $matcher->match($source, [
            new FuzzyStreetNameCandidate($tokenizer->tokenize('VIA ENRICO FERMI')),
            new FuzzyStreetNameCandidate($tokenizer->tokenize('VIA EDOARDO FERMI')),
            new FuzzyStreetNameCandidate($tokenizer->tokenize('VIA ENRICO FERMl')),
        ]);

        self::assertSame(FuzzyStreetResolutionStatus::AMBIGUOUS, $result->status);
    }

    public function testExactCanonicalCandidateMakesFuzzyNotApplicable(): void
    {
        $tokenizer = new StreetNameTokenizer();
        $source = $tokenizer->tokenize("Via D`Annunzio");
        self::assertNotNull($source);

        $result = (new FuzzyStreetMatcher())->match($source, [
            new FuzzyStreetNameCandidate($tokenizer->tokenize("VIA D'ANNUNZIO")),
        ]);

        self::assertSame(FuzzyStreetResolutionStatus::NOT_APPLICABLE, $result->status);
    }
}
