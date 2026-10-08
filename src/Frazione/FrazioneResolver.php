<?php

declare(strict_types=1);

namespace Normalizzatore\Frazione;

use Normalizzatore\Directory\DirectoryKeyNormalizer;

/** Resolves exact fraction names only; directory-confirmed municipality/province pairs are required. */
final readonly class FrazioneResolver
{
    public function __construct(private DirectoryKeyNormalizer $keyNormalizer = new DirectoryKeyNormalizer())
    {
    }

    /**
     * @param list<FrazioneEntry> $entries
     * @param list<array{comune:string,provincia:string}> $verifiedMunicipalities
     */
    public function resolve(string $sourceName, ?string $sourceProvince, array $entries, array $verifiedMunicipalities): FrazioneResolution
    {
        if (trim($sourceName) === '') {
            return new FrazioneResolution(FrazioneResolutionStatus::NOT_APPLICABLE, $sourceName);
        }
        if ($entries === []) {
            return new FrazioneResolution(FrazioneResolutionStatus::NO_MATCH, $sourceName);
        }
        usort($entries, static fn (FrazioneEntry $a, FrazioneEntry $b): int =>
            [$a->comune, $a->provincia, $a->tipo, $a->cap, $a->frazione]
            <=> [$b->comune, $b->provincia, $b->tipo, $b->cap, $b->frazione]);

        $verified = [];
        foreach ($verifiedMunicipalities as $pair) {
            $key = $this->keyNormalizer->normalize($pair['comune']) . "\0" . $this->keyNormalizer->normalize($pair['provincia']);
            $verified[$key] = $pair;
        }
        $mapped = [];
        $incompleteEntries = [];
        $unknownType = false;
        $incompleteAlternative = false;
        foreach ($entries as $entry) {
            $type = trim($entry->tipo);
            if (!in_array($type, [FrazioneTypeGroup::CENTRO_ABITATO->value, FrazioneTypeGroup::NUCLEO_ABITATO->value], true)) {
                $unknownType = true;
            }
            if (trim($entry->comune) === '' || trim($entry->provincia) === '') {
                $incompleteAlternative = true;
                $incompleteEntries[] = $entry;
                continue;
            }
            $key = $this->keyNormalizer->normalize($entry->comune) . "\0" . $this->keyNormalizer->normalize($entry->provincia);
            if (isset($verified[$key])) {
                $mapped[$key] = $verified[$key];
            } else {
                $incompleteAlternative = true;
                $incompleteEntries[] = $entry;
            }
        }
        $all = array_values($mapped);
        if ($incompleteAlternative) {
            return new FrazioneResolution(
                FrazioneResolutionStatus::INDETERMINATE,
                $sourceName,
                $entries,
                $all,
                typeGroup: $unknownType ? FrazioneTypeGroup::UNKNOWN : $this->typeGroup($entries),
                diagnostic: FrazioneResolutionDiagnostic::INCOMPLETE_TERRITORIAL_ALTERNATIVE,
                incompleteAlternatives: $incompleteEntries,
            );
        }
        $province = $this->keyNormalizer->normalize((string) $sourceProvince);
        if ($province !== '') {
            $scoped = array_values(array_filter($all, fn (array $pair): bool => $this->keyNormalizer->normalize($pair['provincia']) === $province));
            if ($scoped !== []) {
                $all = $scoped;
            }
        }
        $pairs = [];
        foreach ($all as $pair) {
            $pairs[$this->keyNormalizer->normalize($pair['comune']) . "\0" . $this->keyNormalizer->normalize($pair['provincia'])] = $pair;
        }
        $all = array_values($pairs);
        usort($all, static fn (array $a, array $b): int => [$a['provincia'], $a['comune']] <=> [$b['provincia'], $b['comune']]);
        if ($all === []) {
            return new FrazioneResolution(FrazioneResolutionStatus::INDETERMINATE, $sourceName, $entries, [], typeGroup: $unknownType ? FrazioneTypeGroup::UNKNOWN : $this->typeGroup($entries), diagnostic: $unknownType ? FrazioneResolutionDiagnostic::UNKNOWN_TYPE : FrazioneResolutionDiagnostic::NO_DIRECTORY_VERIFIED_MUNICIPALITY);
        }
        if (count($all) > 1) {
            return new FrazioneResolution(FrazioneResolutionStatus::AMBIGUOUS, $sourceName, $entries, $all, typeGroup: $this->typeGroup($entries), diagnostic: FrazioneResolutionDiagnostic::MULTIPLE_VERIFIED_MUNICIPALITIES);
        }
        return new FrazioneResolution(FrazioneResolutionStatus::MATCH, $sourceName, $entries, $all, $all[0]['comune'], $all[0]['provincia'], $this->typeGroup($entries));
    }

    /** @param list<FrazioneEntry> $entries */
    private function typeGroup(array $entries): FrazioneTypeGroup
    {
        $types = [];
        foreach ($entries as $entry) {
            $types[trim($entry->tipo)] = true;
        }
        $recognized = array_intersect(array_keys($types), [FrazioneTypeGroup::CENTRO_ABITATO->value, FrazioneTypeGroup::NUCLEO_ABITATO->value]);
        if (count($recognized) === 2) {
            return FrazioneTypeGroup::MIXED;
        }
        if (count(array_diff(array_keys($types), [FrazioneTypeGroup::CENTRO_ABITATO->value, FrazioneTypeGroup::NUCLEO_ABITATO->value])) > 0) {
            return FrazioneTypeGroup::UNKNOWN;
        }
        if (count($recognized) === 1 && count($types) === 1) {
            return $recognized[0] === FrazioneTypeGroup::CENTRO_ABITATO->value ? FrazioneTypeGroup::CENTRO_ABITATO : FrazioneTypeGroup::NUCLEO_ABITATO;
        }
        return FrazioneTypeGroup::UNKNOWN;
    }
}
