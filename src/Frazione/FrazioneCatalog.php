<?php

declare(strict_types=1);

namespace Normalizzatore\Frazione;

use Normalizzatore\Directory\DirectoryKeyNormalizer;
use RuntimeException;
use SplFileObject;
use UnexpectedValueException;

/** Lazy, in-memory exact-name index of the distributed frazioni.tsv source. */
final class FrazioneCatalog
{
    /** @var array<string, list<FrazioneEntry>>|null */
    private ?array $byName = null;
    private int $rowCount = 0;

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
            }
            ++$this->rowCount;
        }
        $this->byName = $index;
    }
}
