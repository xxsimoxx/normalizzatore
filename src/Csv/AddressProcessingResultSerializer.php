<?php

declare(strict_types=1);

namespace Normalizzatore\Csv;

use Normalizzatore\Application\AddressProcessingResult;
use Normalizzatore\Normalization\NormalizedField;
use Normalizzatore\Normalization\NormalizedFieldName;
use Normalizzatore\Normalization\NormalizedFieldStatus;

/** Pure mapping of a processing result to the ten appended CSV columns. */
final class AddressProcessingResultSerializer
{
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
                    . '->' . $this->quote($correction->proposedValue) . ':' . $correction->reason->value;
            }
        }

        $diagnostics = [];
        foreach ($result->resolution->diagnostics as $diagnostic) {
            $diagnostics[] = 'RESOLUTION:' . $diagnostic->value;
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
            $this->outputField($fields->street),
            $this->outputField($fields->houseNumber),
            $this->outputField($fields->civicDetails),
            $result->normalizedCap() ?? '',
            $this->outputField($fields->city),
            $this->outputField($fields->province),
            $result->resolution->status->name,
            $result->capVerification->status->name,
            implode(' | ', $corrections),
            implode(' | ', $diagnostics),
        ];
    }

    private function outputField(NormalizedField $field): string
    {
        return in_array($field->status, [
            NormalizedFieldStatus::CONFIRMED,
            NormalizedFieldStatus::SYNTAX_NORMALIZED,
            NormalizedFieldStatus::DIRECTORY_CORRECTION,
        ], true) ? ($field->normalizedValue ?? '') : '';
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
