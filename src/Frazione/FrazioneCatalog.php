<?php

declare(strict_types=1);

namespace Normalizzatore\Frazione;

use Normalizzatore\Directory\DirectoryKeyNormalizer;
use RuntimeException;
use SplFileObject;
use UnexpectedValueException;

/** Lazy, in-memory exact-name index of the distributed frazioni.tsv source. */
final class FrazioneCatalog implements FuzzyFrazioneCandidateProvider
{
    /** @var array<string, list<FrazioneEntry>>|null */
    private ?array $byName = null;
    private int $rowCount = 0;

    /** @var array<string, true>|null */
    private ?array $letterAlphabet = null;

    public function __construct(
        private readonly string $path,
        private readonly DirectoryKeyNormalizer $keyNormalizer = new DirectoryKeyNormalizer(),
    ) {
    }

    /** @return list<FrazioneEntry> */
    public function find(string $name): array
    {
        $this->load();
        $key = $this->keyNormalizer->normalize($name);
        return $this->byName[$key] ?? [];
    }

    /**
     * Finds a bounded set of catalog entries by generating one-edit variants of
     * eligible nominal tokens and probing the already-built exact-name hash.
     * No catalog-wide fuzzy index or scan is created.
     *
     * @return list<FrazioneEntry>
     */
    public function findFuzzyCandidates(string $sourceName, FuzzyFrazioneMatchingOptions $options): array
    {
        $this->load();
        $sourceKey = $this->keyNormalizer->normalize($sourceName);
        $tokens = $sourceKey === '' ? [] : explode(' ', $sourceKey);
        $alphabet = array_keys($this->letterAlphabet ?? []);
        $candidateKeys = [];
        foreach ($tokens as $position => $token) {
            $characters = mb_str_split($token, 1, 'UTF-8');
            $length = count($characters);
            if ($length < $options->minimumTokenLength || preg_match('/\A\p{L}+\z/u', $token) !== 1) {
                continue;
            }

            // Deletion from source (candidate is shorter).
            for ($i = 0; $i < $length; ++$i) {
                $variant = $characters;
                array_splice($variant, $i, 1);
                $this->collectExactVariant($tokens, $position, implode('', $variant), $options, $candidateKeys);
            }
            // Insertion into source (candidate is longer).
            for ($i = 0; $i <= $length; ++$i) {
                foreach ($alphabet as $letter) {
                    $variant = $characters;
                    array_splice($variant, $i, 0, [$letter]);
                    $this->collectExactVariant($tokens, $position, implode('', $variant), $options, $candidateKeys);
                }
            }
            // Substitution.
            for ($i = 0; $i < $length; ++$i) {
                foreach ($alphabet as $letter) {
                    if ($letter === $characters[$i]) {
                        continue;
                    }
                    $variant = $characters;
                    $variant[$i] = $letter;
                    $this->collectExactVariant($tokens, $position, implode('', $variant), $options, $candidateKeys);
                }
            }
            // Adjacent transposition.
            for ($i = 0; $i + 1 < $length; ++$i) {
                if ($characters[$i] === $characters[$i + 1]) {
                    continue;
                }
                $variant = $characters;
                [$variant[$i], $variant[$i + 1]] = [$variant[$i + 1], $variant[$i]];
                $this->collectExactVariant($tokens, $position, implode('', $variant), $options, $candidateKeys);
            }
        }

        ksort($candidateKeys, SORT_STRING);
        $entries = [];
        foreach (array_keys($candidateKeys) as $key) {
            array_push($entries, ...($this->byName[$key] ?? []));
        }
        return $entries;
    }

    public function rowCount(): int
    {
        $this->load();
        return $this->rowCount;
    }

    public function isLoaded(): bool
    {
        return $this->byName !== null;
    }

    private function load(): void
    {
        if ($this->byName !== null) {
            return;
        }
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new RuntimeException(sprintf('Frazione catalog is not readable: %s', $this->path));
        }
        $file = new SplFileObject($this->path, 'rb');
        $index = [];
        $alphabet = [];
        foreach ($file as $line => $text) {
            if (!is_string($text)) {
                continue;
            }
            $text = rtrim($text, "\r\n");
            if ($line === 0 && str_starts_with($text, "\xEF\xBB\xBF")) {
                $text = substr($text, 3);
            }
            if ($line === 0) {
                if ($text !== "CAP\tCOMUNE\tFRAZIONE\tPROVINCIA\tTIPO") {
                    throw new UnexpectedValueException('Invalid frazioni.tsv header.');
                }
                continue;
            }
            if ($text === '' && $file->eof()) {
                continue;
            }
            $columns = explode("\t", $text);
            if (count($columns) !== 5) {
                throw new UnexpectedValueException(sprintf('Invalid frazione catalog row at line %d.', $line + 1));
            }
            [$cap, $comune, $frazione, $provincia, $tipo] = $columns;
            $entry = new FrazioneEntry($cap, $comune, $frazione, $provincia, $tipo, $line + 1);
            $key = $this->keyNormalizer->normalize($frazione);
            if ($key !== '') {
                $index[$key][] = $entry;
                foreach (preg_split('//u', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
                    if (preg_match('/\A\p{L}\z/u', $character) === 1) {
                        $alphabet[$character] = true;
                    }
                }
            }
            ++$this->rowCount;
        }
        $this->byName = $index;
        $this->letterAlphabet = $alphabet;
    }

    /** @param list<string> $tokens @param array<string, true> $candidateKeys */
    private function collectExactVariant(array $tokens, int $position, string $variant, FuzzyFrazioneMatchingOptions $options, array &$candidateKeys): void
    {
        if (mb_strlen($variant, 'UTF-8') < $options->minimumTokenLength) {
            return;
        }
        $candidateTokens = $tokens;
        $candidateTokens[$position] = $variant;
        $key = implode(' ', $candidateTokens);
        if (isset($this->byName[$key])) {
            $candidateKeys[$key] = true;
        }
    }
}
