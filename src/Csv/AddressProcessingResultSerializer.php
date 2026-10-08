<?php

declare(strict_types=1);

namespace Normalizzatore\Csv;

use Normalizzatore\Application\AddressProcessingResult;
use Normalizzatore\Normalization\NormalizedField;
use Normalizzatore\Normalization\NormalizedFieldName;
use Normalizzatore\Normalization\NormalizedFieldStatus;
use Normalizzatore\Text\OrthographyNormalizer;
use Normalizzatore\Frazione\FuzzyFrazioneResolutionStatus;

/** Pure mapping of a processing result to the ten appended CSV columns. */
final class AddressProcessingResultSerializer
{
    public function __construct(private OrthographyNormalizer $orthographyNormalizer = new OrthographyNormalizer())
    {
    }

    public const OUTPUT_COLUMNS = [
        'via_normalizzata', 'civico_normalizzato', 'dettagli_normalizzati', 'cap_normalizzato',
        'citta_normalizzata', 'provincia_normalizzata', 'stato_risoluzione', 'verifica_cap',
        'correzioni_suggerite', 'diagnostica',
    ];

    /** @return list<string> */
    public function serialize(AddressProcessingResult $result): array
    {
        $fields = $result->fieldNormalization;
        $corrections = [];
        if ($result->sourceCapCorrection() !== null) {
            $correction = $result->sourceCapCorrection();
            $corrections[] = 'CAP:' . $this->quote($correction->sourceCapOriginal) . '->' . $this->quote($correction->proposedCap)
                . ':' . $correction->reason->value;
        }
        foreach ([$fields->street, $fields->houseNumber, $fields->civicDetails, $fields->city, $fields->province] as $field) {
            if ($field->correction !== null) {
                $correction = $field->correction;
                $corrections[] = $this->fieldCode($correction->field) . ':' . $this->quote($correction->originalValue)
                    . '->' . $this->quote($this->canonicalCorrectionValue($correction->field, $correction->proposedValue)) . ':' . $correction->reason->value;
            }
        }
        $fuzzyFrazione = $result->resolution->fuzzyFrazioneResolution;
        if ($fuzzyFrazione?->status === FuzzyFrazioneResolutionStatus::SUGGESTED
            && $fuzzyFrazione->selectedCandidate !== null) {
            $municipality = $fuzzyFrazione->territorialResolution?->comune ?? '';
            $suggested = $municipality !== '' ? $municipality : $fuzzyFrazione->selectedCandidate->canonicalName;
            $corrections[] = 'SUGGERIMENTO_CITTA:' . $this->quote($fuzzyFrazione->sourceName)
                . '->' . $this->quote($suggested) . ':fuzzy_frazione_osa1_non_applicato';
        }

        $diagnostics = [];
        foreach ($result->resolution->diagnostics as $diagnostic) {
            $diagnostics[] = 'RESOLUTION:' . $diagnostic->value;
        }
        $frazione = $result->resolution->frazioneResolution;
        if ($frazione !== null && !in_array($frazione->status, [
            \Normalizzatore\Frazione\FrazioneResolutionStatus::NO_MATCH,
            \Normalizzatore\Frazione\FrazioneResolutionStatus::NOT_APPLICABLE,
        ], true)) {
            $tipo = $frazione->typeGroup->value;
            $sourceTypes = array_values(array_unique(array_filter(array_map(
                static fn ($entry): string => trim($entry->tipo),
                $frazione->entries,
            ), static fn (string $value): bool => $value !== '')));
            sort($sourceTypes, SORT_STRING);
            $comune = $frazione->comune ?? '';
            $candidates = array_map(
                static fn (array $candidate): string => $candidate['comune'] . '/' . $candidate['provincia'],
                $frazione->candidateMunicipalities,
            );
            $diagnostics[] = 'FRAZIONE:' . $frazione->status->value
                . ':origine=' . $this->quote($frazione->sourceName)
                . ':comune=' . $this->quote($comune)
                . ':tipo=' . $this->quote($sourceTypes === [] ? $tipo : implode(',', $sourceTypes))
                . ($frazione->diagnostic === null ? '' : ':causa=' . $frazione->diagnostic->value)
                . ($candidates === [] ? '' : ':candidati=' . $this->quote(implode(',', $candidates)))
                . ($frazione->incompleteAlternatives === [] ? '' : ':alternative_incomplete=' . $this->quote(implode(',', array_map(
                    static fn ($entry): string => ($entry->comune === '' ? '[comune mancante]' : $entry->comune)
                        . '/' . ($entry->provincia === '' ? '[provincia mancante]' : $entry->provincia)
                        . '[' . $entry->tipo . '; CAP ' . ($entry->cap === '' ? 'assente' : $entry->cap) . ']'
                        . '#riga ' . $entry->lineNumber,
                    $frazione->incompleteAlternatives,
                ))))
                . ($frazione->catalogCapConflict ? ':catalog_cap_conflict' : '')
                . ($frazione->streetEvidence === null ? '' : ':via_evidence=' . $frazione->streetEvidence->status->value
                    . ($frazione->streetEvidence->streetName === null ? '' : ':via=' . $this->quote($frazione->streetEvidence->streetName))
                    . ($frazione->streetEvidence->civicNumber === null ? '' : ':civico=' . $this->quote($frazione->streetEvidence->civicNumber))
                    . ':directory_entries=' . $frazione->streetEvidence->exactDirectoryEntries
                    . ($frazione->streetEvidence->civicResolutionStatus === null ? '' : ':cap_resolver=' . $frazione->streetEvidence->civicResolutionStatus->name)
                    . ($frazione->streetEvidence->directoryCaps === [] ? '' : ':directory_caps=' . $this->quote(implode(',', $frazione->streetEvidence->directoryCaps))));
        }
        if ($fuzzyFrazione !== null) {
            $candidateDescriptions = [];
            foreach ($fuzzyFrazione->candidates as $candidate) {
                $types = array_values(array_unique(array_map(static fn ($entry): string => trim($entry->tipo), $candidate->entries)));
                sort($types, SORT_STRING);
                $candidateDescriptions[] = $candidate->canonicalName
                    . '[OSA=' . $candidate->distance
                    . ',operazione=' . $candidate->operation->value
                    . ',TIPO=' . implode(',', $types)
                    . ']';
            }
            $selected = $fuzzyFrazione->selectedCandidate;
            $territory = $fuzzyFrazione->territorialResolution;
            $diagnostics[] = 'FRAZIONE_FUZZY:' . $fuzzyFrazione->status->value
                . ':origine=' . $this->quote($fuzzyFrazione->sourceName)
                . ':provincia_sorgente=' . $this->quote($fuzzyFrazione->sourceProvince ?? '')
                . ($selected === null ? '' : ':candidato=' . $this->quote($selected->canonicalName))
                . ($territory?->comune === null ? '' : ':comune=' . $this->quote($territory->comune))
                . ($territory?->provincia === null ? '' : ':provincia=' . $this->quote($territory->provincia))
                . ($territory?->typeGroup === null ? '' : ':tipo=' . $this->quote($territory->typeGroup->value))
                . ($fuzzyFrazione->diagnostic === null ? '' : ':causa=' . $fuzzyFrazione->diagnostic->value)
                . ($candidateDescriptions === [] ? '' : ':candidati=' . $this->quote(implode(',', $candidateDescriptions)))
                . ($territory?->diagnostic === null ? '' : ':territorio=' . $territory->diagnostic->value)
                . ($territory === null || $territory->incompleteAlternatives === [] ? '' : ':alternative_incomplete=' . count($territory->incompleteAlternatives))
                . ($fuzzyFrazione->streetEvidence === null ? '' : ':via_evidence=' . $fuzzyFrazione->streetEvidence->status->value
                    . ($fuzzyFrazione->streetEvidence->streetName === null ? '' : ':via=' . $this->quote($fuzzyFrazione->streetEvidence->streetName))
                    . ($fuzzyFrazione->streetEvidence->civicNumber === null ? '' : ':civico=' . $this->quote($fuzzyFrazione->streetEvidence->civicNumber))
                    . ':directory_entries=' . $fuzzyFrazione->streetEvidence->exactDirectoryEntries
                    . ($fuzzyFrazione->streetEvidence->civicResolutionStatus === null ? '' : ':cap_resolver=' . $fuzzyFrazione->streetEvidence->civicResolutionStatus->name));
        }
        foreach ($result->capVerification->diagnostics as $diagnostic) {
            $diagnostics[] = 'CAP:' . $diagnostic->value;
        }
        foreach ([
            [NormalizedFieldName::STREET, $fields->street],
            [NormalizedFieldName::HOUSE_NUMBER, $fields->houseNumber],
            [NormalizedFieldName::CIVIC_DETAILS, $fields->civicDetails],
            [NormalizedFieldName::CITY, $fields->city],
            [NormalizedFieldName::PROVINCE, $fields->province],
        ] as [$name, $field]) {
            foreach ($field->diagnostics as $diagnostic) {
                $diagnostics[] = $this->fieldCode($name) . ':' . $diagnostic->value;
            }
        }

        return [
            $this->outputField($fields->street, true),
            $this->outputField($fields->houseNumber),
            $this->outputField($fields->civicDetails),
            $result->normalizedCap() ?? '',
            $this->outputField($fields->city, true),
            $this->outputField($fields->province),
            $result->resolution->status->name,
            $result->capVerification->status->name,
            implode(' | ', $corrections),
            implode(' | ', $diagnostics),
        ];
    }

    private function outputField(NormalizedField $field, bool $canonicalizeOrthography = false): string
    {
        if (!in_array($field->status, [
            NormalizedFieldStatus::CONFIRMED,
            NormalizedFieldStatus::SYNTAX_NORMALIZED,
            NormalizedFieldStatus::DIRECTORY_CORRECTION,
        ], true)) {
            return '';
        }

        $value = $field->normalizedValue ?? '';

        return $canonicalizeOrthography ? $this->orthographyNormalizer->normalize($value) : $value;
    }

    private function canonicalCorrectionValue(NormalizedFieldName $field, string $value): string
    {
        return in_array($field, [NormalizedFieldName::STREET, NormalizedFieldName::CITY], true)
            ? $this->orthographyNormalizer->normalize($value)
            : $value;
    }

    private function fieldCode(NormalizedFieldName $field): string
    {
        return match ($field) {
            NormalizedFieldName::STREET => 'VIA',
            NormalizedFieldName::HOUSE_NUMBER => 'CIVICO',
            NormalizedFieldName::CIVIC_DETAILS => 'DETTAGLI',
            NormalizedFieldName::CITY => 'CITTA',
            NormalizedFieldName::PROVINCE => 'PROVINCIA',
        };
    }

    private function quote(string $value): string
    {
        $escaped = str_replace('\\', '\\\\', $value);
        $escaped = str_replace('"', '""', $escaped);
        $escaped = str_replace(['|', ':', '->'], ['\\|', '\\:', '\\->'], $escaped);

        return '"' . $escaped . '"';
    }
}
