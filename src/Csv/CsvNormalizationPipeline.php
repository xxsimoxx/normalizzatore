<?php

declare(strict_types=1);

namespace Normalizzatore\Csv;

use Normalizzatore\Address\AddressInput;
use Normalizzatore\Application\AddressProcessor;
use Normalizzatore\Resolution\AddressResolutionStatus;
use RuntimeException;
use Throwable;

final readonly class CsvNormalizationPipeline
{
    public function __construct(
        private CsvReader $reader,
        private CsvWriter $writer,
        private AddressProcessor $processor,
        private AddressProcessingResultSerializer $serializer,
    ) {
    }

    public function run(string $inputPath, string $outputPath, string $delimiter = ';'): CsvNormalizationSummary
    {
        $started = hrtime(true);
        if (!is_file($inputPath) || !is_readable($inputPath)) {
            throw new RuntimeException(sprintf('Input CSV is not a readable file: %s', $inputPath));
        }
        if (file_exists($outputPath) || is_link($outputPath)) {
            throw new RuntimeException(sprintf('Output file already exists: %s', $outputPath));
        }

        $stream = $this->reader->open($inputPath, $delimiter);
        $headerMap = CsvHeaderMap::fromHeader($stream->header, AddressProcessingResultSerializer::OUTPUT_COLUMNS);
        $outputDirectory = dirname($outputPath);
        if (!is_dir($outputDirectory) || !is_writable($outputDirectory)) {
            throw new RuntimeException(sprintf('Output directory is not writable: %s', $outputDirectory));
        }
        if (file_exists($outputPath) || is_link($outputPath)) {
            throw new RuntimeException(sprintf('Output file already exists: %s', $outputPath));
        }

        $temporaryPath = tempnam($outputDirectory, '.' . basename($outputPath) . '.tmp-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Unable to create a temporary output file.');
        }
        $handle = null;
        $published = false;
        try {
            $handle = fopen($temporaryPath, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Unable to open the temporary output file.');
            }
            $this->writer->writeRecord($handle, [...$stream->header, ...AddressProcessingResultSerializer::OUTPUT_COLUMNS], $delimiter);

            $processed = $resolved = $ambiguous = $unresolved = $rowsWithCorrections = 0;
            foreach ($stream->rows() as $row) {
                if (count($row) !== count($stream->header)) {
                    throw new RuntimeException(sprintf('Malformed CSV row %d: expected %d columns, got %d.', $processed + 2, count($stream->header), count($row)));
                }
                $input = $headerMap->addressInput($row);
                $result = $this->processor->process($input);
                $this->writer->writeRecord($handle, [...$row, ...$this->serializer->serialize($result)], $delimiter);
                ++$processed;
                match ($result->resolution->status) {
                    AddressResolutionStatus::RESOLVED => ++$resolved,
                    AddressResolutionStatus::AMBIGUOUS => ++$ambiguous,
                    AddressResolutionStatus::NO_MATCH, AddressResolutionStatus::INDETERMINATE => ++$unresolved,
                };
                if ($result->sourceCapCorrection() !== null || $result->fieldCorrections() !== []) {
                    ++$rowsWithCorrections;
                }
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Unable to flush the temporary output file.');
            }
            if (function_exists('fsync') && !fsync($handle)) {
                throw new RuntimeException('Unable to synchronize the temporary output file.');
            }
            fclose($handle);
            $handle = null;

            // link() publishes atomically without replacing a path created concurrently.
            if (!@link($temporaryPath, $outputPath)) {
                throw new RuntimeException(sprintf('Unable to publish output without overwriting an existing path: %s', $outputPath));
            }
            $published = true;
            if (!@unlink($temporaryPath)) {
                throw new RuntimeException('Output was published, but its temporary link could not be removed.');
            }

            return new CsvNormalizationSummary(
                $processed,
                $resolved,
                $ambiguous,
                $unresolved,
                $rowsWithCorrections,
                (hrtime(true) - $started) / 1_000_000_000,
            );
        } catch (Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            @unlink($temporaryPath);
            if ($published) {
                @unlink($outputPath);
            }
            throw $exception;
        }
    }
}
