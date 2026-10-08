<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Frazione;

use Normalizzatore\Frazione\FrazioneCatalog;
use Normalizzatore\Frazione\FuzzyFrazioneEditOperation;
use Normalizzatore\Frazione\FuzzyFrazioneMatchStatus;
use Normalizzatore\Frazione\FuzzyFrazioneMatcher;
use Normalizzatore\Frazione\FuzzyFrazioneMatchingOptions;
use PHPUnit\Framework\TestCase;

final class FuzzyFrazioneMatcherTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/normalizzatore-fuzzy-frazione-' . bin2hex(random_bytes(5));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public function testRecognizesFienileAsOneDeletionWithoutBuildingAnotherIndex(): void
    {
        $catalog = $this->catalog("45100\tRovigo\tFienil del Turco\tRO\tCentro abitato\n");
        $matcher = new FuzzyFrazioneMatcher();
        self::assertFalse($catalog->isLoaded());
        $entries = $catalog->findFuzzyCandidates('FIENILE DEL TURCO', $matcher->options());
        self::assertTrue($catalog->isLoaded());
        $match = $matcher->match('FIENILE DEL TURCO', $entries);

        self::assertSame(FuzzyFrazioneMatchStatus::MATCH, $match->status);
        self::assertSame('FIENIL DEL TURCO', $match->match?->canonicalName);
        self::assertSame(1, $match->match?->distance);
        self::assertSame(FuzzyFrazioneEditOperation::DELETION, $match->match?->operation);
        self::assertSame('Centro abitato', $match->match?->entries[0]->tipo);
    }

    public function testTwoCandidateSpellingsRemainAmbiguousRegardlessOfInputOrder(): void
    {
        $catalog = $this->catalog(
            "45100\tRovigo\tFienil del Turco\tRO\tCentro abitato\n"
            . "45100\tRovigo\tFienile del Turci\tRO\tNucleo abitato\n",
        );
        $matcher = new FuzzyFrazioneMatcher();
        $entries = $catalog->findFuzzyCandidates('FIENILE DEL TURCO', $matcher->options());
        $first = $matcher->match('FIENILE DEL TURCO', $entries);
        $second = $matcher->match('FIENILE DEL TURCO', array_reverse($entries));

        self::assertSame(FuzzyFrazioneMatchStatus::AMBIGUOUS, $first->status);
        self::assertSame(['FIENIL DEL TURCO', 'FIENILE DEL TURCI'], array_map(static fn ($candidate): string => $candidate->canonicalName, $first->candidates));
        self::assertSame(
            array_map(static fn ($candidate): string => $candidate->canonicalName, $first->candidates),
            array_map(static fn ($candidate): string => $candidate->canonicalName, $second->candidates),
        );
        self::assertNull($first->match);
    }

    public function testShortTokensAndMultipleTokenChangesAreNotApplicableOrMatched(): void
    {
        $catalog = $this->catalog(
            "00100\tRoma\tCasa Nova\tRM\tCentro abitato\n"
            . "45100\tRovigo\tFienil dal Turco\tRO\tCentro abitato\n",
        );
        $matcher = new FuzzyFrazioneMatcher();
        $short = $matcher->match('CASO NOVA', $catalog->findFuzzyCandidates('CASO NOVA', $matcher->options()));
        $multiple = $matcher->match('FIENILE DEL TURCO', $catalog->findFuzzyCandidates('FIENILE DEL TURCO', $matcher->options()));
        $differentTokenCount = $matcher->match('FIENILE TURCO', $catalog->findFuzzyCandidates('FIENILE TURCO', $matcher->options()));

        self::assertSame(FuzzyFrazioneMatchStatus::NOT_APPLICABLE, $short->status);
        self::assertSame(FuzzyFrazioneMatchStatus::NO_MATCH, $multiple->status);
        self::assertSame(FuzzyFrazioneMatchStatus::NO_MATCH, $differentTokenCount->status);
    }

    public function testMinimumTokenLengthIsConfigurableAndValidated(): void
    {
        $catalog = $this->catalog("00100\tRoma\tCasale\tRM\tNucleo abitato\n");
        $strict = new FuzzyFrazioneMatcher(new FuzzyFrazioneMatchingOptions(7));
        $loose = new FuzzyFrazioneMatcher(new FuzzyFrazioneMatchingOptions(5));

        self::assertSame(FuzzyFrazioneMatchStatus::NOT_APPLICABLE, $strict->match('Casali', $catalog->findFuzzyCandidates('Casali', $strict->options()))->status);
        self::assertSame(FuzzyFrazioneMatchStatus::MATCH, $loose->match('Casali', $catalog->findFuzzyCandidates('Casali', $loose->options()))->status);
        $this->expectException(\InvalidArgumentException::class);
        new FuzzyFrazioneMatchingOptions(0);
    }

    public function testAuditExamplesAreSuggestedOrAmbiguousRatherThanSilentlyChosen(): void
    {
        $catalog = new FrazioneCatalog(dirname(__DIR__, 2) . '/resources/frazioni.tsv');
        $matcher = new FuzzyFrazioneMatcher();

        $fener = $matcher->match('FENER', $catalog->findFuzzyCandidates('FENER', $matcher->options()));
        $quero = $matcher->match('QUERO', $catalog->findFuzzyCandidates('QUERO', $matcher->options()));
        $onara = $matcher->match('ONARA', $catalog->findFuzzyCandidates('ONARA', $matcher->options()));
        $onigo = $matcher->match('ONIGO', $catalog->findFuzzyCandidates('ONIGO', $matcher->options()));

        self::assertSame(FuzzyFrazioneMatchStatus::MATCH, $fener->status);
        self::assertSame('FEDER', $fener->match?->canonicalName);
        self::assertSame(FuzzyFrazioneMatchStatus::MATCH, $quero->status);
        self::assertSame('QUERS', $quero->match?->canonicalName);
        self::assertSame(FuzzyFrazioneMatchStatus::AMBIGUOUS, $onara->status);
        self::assertSame(['ONARI', 'ONARO'], array_map(static fn ($candidate): string => $candidate->canonicalName, $onara->candidates));
        self::assertSame(FuzzyFrazioneMatchStatus::AMBIGUOUS, $onigo->status);
        self::assertSame(['FONIGO', 'OSIGO'], array_map(static fn ($candidate): string => $candidate->canonicalName, $onigo->candidates));
    }

    private function catalog(string $records): FrazioneCatalog
    {
        $path = $this->directory . '/frazioni.tsv';
        file_put_contents($path, "CAP\tCOMUNE\tFRAZIONE\tPROVINCIA\tTIPO\n" . $records);
        return new FrazioneCatalog($path);
    }
}
