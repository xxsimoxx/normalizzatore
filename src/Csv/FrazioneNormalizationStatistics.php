<?php

declare(strict_types=1);

namespace Normalizzatore\Csv;

use Normalizzatore\Frazione\FrazioneResolutionStatus;
use Normalizzatore\Frazione\FrazioneTypeGroup;

/** Incremental counts of fraction lookups, grouped by the source TIPO evidence. */
final class FrazioneNormalizationStatistics
{
    /** @var array<string, array{lookup:int,match:int,applied:int,ambiguous:int,unverified:int,no_match:int,cap_conflict:int}> */
    private array $counts = [];

    public function record(FrazioneResolutionStatus $status, FrazioneTypeGroup $type, bool $applied, bool $capConflict = false): void
    {
        $key = $type->value;
        $this->counts[$key] ??= ['lookup' => 0, 'match' => 0, 'applied' => 0, 'ambiguous' => 0, 'unverified' => 0, 'no_match' => 0, 'cap_conflict' => 0];
        ++$this->counts[$key]['lookup'];
        match ($status) {
            FrazioneResolutionStatus::MATCH => ++$this->counts[$key]['match'],
            FrazioneResolutionStatus::AMBIGUOUS => ++$this->counts[$key]['ambiguous'],
            FrazioneResolutionStatus::INDETERMINATE => ++$this->counts[$key]['unverified'],
            FrazioneResolutionStatus::NO_MATCH, FrazioneResolutionStatus::NOT_APPLICABLE => ++$this->counts[$key]['no_match'],
        };
        if ($applied) {
            ++$this->counts[$key]['applied'];
        }
        if ($capConflict) {
            ++$this->counts[$key]['cap_conflict'];
        }
    }

    /** @return array<string, array{lookup:int,match:int,applied:int,ambiguous:int,unverified:int,no_match:int}> */
    public function counts(): array
    {
        ksort($this->counts, SORT_STRING);
        return $this->counts;
    }

    public function toText(): string
    {
        $text = "Frazioni (lookup esatto del nome; nessun fuzzy frazioni):\n";
        foreach ($this->counts() as $type => $counts) {
            $text .= sprintf("  %s: lookup %d, riconosciute %d, applicate %d, ambigue %d, non verificabili %d, non trovate %d\n",
                $type, $counts['lookup'], $counts['match'], $counts['applied'], $counts['ambiguous'], $counts['unverified'], $counts['no_match']);
            if ($counts['cap_conflict'] > 0) {
                $text .= sprintf("    CAP catalogo discordante: %d\n", $counts['cap_conflict']);
            }
        }
        return $text;
    }
}
