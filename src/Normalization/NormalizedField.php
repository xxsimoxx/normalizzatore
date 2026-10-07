<?php

declare(strict_types=1);

namespace Normalizzatore\Normalization;

use InvalidArgumentException;

/** Immutable normalization outcome for one address field. */
final readonly class NormalizedField
{
    /**
     * @param list<AddressFieldDiagnostic> $diagnostics
     */
    public function __construct(
        public NormalizedFieldName $field,
        public ?string $originalValue,
        public ?string $normalizedValue,
        public NormalizationOrigin $origin,
        public NormalizedFieldStatus $status,
        public ?FieldCorrection $correction = null,
        public array $diagnostics = [],
    ) {
        if (!array_is_list($diagnostics)) {
            throw new InvalidArgumentException('Field diagnostics must be a list.');
        }
        foreach ($diagnostics as $diagnostic) {
            if (!$diagnostic instanceof AddressFieldDiagnostic) {
                throw new InvalidArgumentException('Field diagnostics must use AddressFieldDiagnostic values.');
            }
        }
        if (count(array_unique(array_map(static fn (AddressFieldDiagnostic $item): string => $item->value, $diagnostics))) !== count($diagnostics)) {
            throw new InvalidArgumentException('Field diagnostics must be unique.');
        }
        if ($correction !== null && ($correction->field !== $field
            || $correction->originalValue !== ($originalValue ?? '')
            || $correction->proposedValue !== $normalizedValue)) {
            throw new InvalidArgumentException('Field correction must match its field values.');
        }
        if ($status === NormalizedFieldStatus::MISSING
            && ($normalizedValue !== null || ($originalValue !== null && trim($originalValue) !== ''))) {
            throw new InvalidArgumentException('Missing status must correspond to an empty field value.');
        }
        if ($status === NormalizedFieldStatus::DIRECTORY_CORRECTION
            && ($correction === null || $correction->origin !== NormalizationOrigin::DIRECTORY)) {
            throw new InvalidArgumentException('A directory correction status requires directory evidence.');
        }
        if ($status === NormalizedFieldStatus::SYNTAX_NORMALIZED
            && ($origin !== NormalizationOrigin::SYNTAX
                || ($correction !== null && $correction->origin !== NormalizationOrigin::SYNTAX))) {
            throw new InvalidArgumentException('A syntax-normalized status requires a syntax origin.');
        }
        if ($status === NormalizedFieldStatus::AMBIGUOUS
            && $correction !== null) {
            throw new InvalidArgumentException('An ambiguous field cannot have a correction.');
        }
        if ($status === NormalizedFieldStatus::UNVERIFIABLE
            && $correction?->origin === NormalizationOrigin::DIRECTORY) {
            throw new InvalidArgumentException('An unverifiable field cannot have a directory correction.');
        }
    }
}
