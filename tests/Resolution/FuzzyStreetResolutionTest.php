<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Resolution;

use InvalidArgumentException;
use Normalizzatore\Address\StreetNameTokenizer;
use Normalizzatore\Resolution\FuzzyStreetDiagnostic;
use Normalizzatore\Resolution\FuzzyStreetMatchEvidence;
use Normalizzatore\Resolution\FuzzyStreetMatchKind;
use Normalizzatore\Resolution\FuzzyStreetResolution;
use Normalizzatore\Resolution\FuzzyStreetResolutionStatus;
use PHPUnit\Framework\TestCase;

final class FuzzyStreetResolutionTest extends TestCase
{
    public function testMatchRequiresSelectedEvidenceAndNonMatchCannotCarryIt(): void
    {
        $evidence = $this->typoEvidence('VIA CAPUCCINA', 'VIA CAPPUCCINA');

        self::assertSame($evidence, FuzzyStreetResolution::match($evidence)->match);

        $this->expectException(InvalidArgumentException::class);
        new FuzzyStreetResolution(FuzzyStreetResolutionStatus::NO_MATCH, $evidence);
    }

    public function testAmbiguousResultRequiresUniqueSortedEvidenceFromOneStrategy(): void
    {
        $source = $this->street('VIA E. FERMI');
        $edoardo = new FuzzyStreetMatchEvidence(
            $source,
            $this->street('VIA EDOARDO FERMI'),
            FuzzyStreetMatchKind::ABBREVIATION,
            0,
            'E.',
            'EDOARDO',
        );
        $enrico = new FuzzyStreetMatchEvidence(
            $source,
            $this->street('VIA ENRICO FERMI'),
            FuzzyStreetMatchKind::ABBREVIATION,
            0,
            'E.',
            'ENRICO',
        );

        $result = FuzzyStreetResolution::ambiguous(
            [$edoardo, $enrico],
            [FuzzyStreetDiagnostic::MULTIPLE_ABBREVIATION_EXPANSIONS],
        );
        self::assertSame(FuzzyStreetResolutionStatus::AMBIGUOUS, $result->status);

        $this->expectException(InvalidArgumentException::class);
        FuzzyStreetResolution::ambiguous(
            [$enrico, $edoardo],
            [FuzzyStreetDiagnostic::MULTIPLE_ABBREVIATION_EXPANSIONS],
        );
    }

    public function testEvidenceRejectsInconsistentNormalizedDistance(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FuzzyStreetMatchEvidence(
            $this->street('VIA CAPUCCINA'),
            $this->street('VIA CAPPUCCINA'),
            FuzzyStreetMatchKind::TYPO,
            0,
            'CAPUCCINA',
            'CAPPUCCINA',
            1,
            0.5,
        );
    }

    private function typoEvidence(string $sourceName, string $candidateName): FuzzyStreetMatchEvidence
    {
        $source = $this->street($sourceName);
        $candidate = $this->street($candidateName);

        return new FuzzyStreetMatchEvidence(
            $source,
            $candidate,
            FuzzyStreetMatchKind::TYPO,
            0,
            $source->nominalTokens[0],
            $candidate->nominalTokens[0],
            1,
            1 / max(mb_strlen($source->nominalTokens[0], 'UTF-8'), mb_strlen($candidate->nominalTokens[0], 'UTF-8')),
        );
    }

    private function street(string $name): \Normalizzatore\Address\TokenizedStreetName
    {
        return (new StreetNameTokenizer())->tokenize($name) ?? self::fail('Expected a supported street name.');
    }
}
