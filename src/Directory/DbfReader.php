<?php

declare(strict_types=1);

namespace Normalizzatore\Directory;

use Generator;
use Throwable;

final class DbfReader
{
    private const REQUIRED_FIELDS = [
        'VIANUM', 'CAP', 'CITTA', 'PR', 'PARI_DISPA', 'CIVICO_DA', 'CIVICO_A',
    ];

    /** @var array<string, array{offset: int, length: int}> */
    private array $fields = [];
    private int $declaredRecordCount;
    private int $headerLength;
    private int $recordLength;
    private int $dbfVersion;
    private int $deletedRecordCount = 0;

    public function __construct(private readonly string $path)
    {
        $this->readHeader();
    }

    public function declaredRecordCount(): int
    {
        return $this->declaredRecordCount;
    }

    public function deletedRecordCount(): int
    {
        return $this->deletedRecordCount;
    }

    public function dbfVersion(): int
    {
        return $this->dbfVersion;
    }

    /** @return Generator<int, DbfRecord> */
    public function records(): Generator
    {
        $stream = @fopen($this->path, 'rb');
        if ($stream === false) {
            throw new DbfReaderException(sprintf('Cannot open DBF file "%s".', $this->path));
        }

        $this->deletedRecordCount = 0;

        try {
            if (fseek($stream, $this->headerLength) !== 0) {
                throw new DbfReaderException('Unable to seek to the first DBF record.');
            }

            for ($recordIndex = 0; $recordIndex < $this->declaredRecordCount; $recordIndex++) {
                $record = $this->readExact($stream, $this->recordLength);
                $deletionFlag = $record[0];

                if ($deletionFlag === '*') {
                    $this->deletedRecordCount++;
                    continue;
                }

                if ($deletionFlag !== ' ') {
                    throw new DbfReaderException(sprintf(
                        'Invalid deletion flag 0x%02X in DBF record %d.',
                        ord($deletionFlag),
                        $recordIndex + 1,
                    ));
                }

                $values = [];
                foreach ($this->fields as $name => $definition) {
                    $rawValue = substr($record, 1 + $definition['offset'], $definition['length']);
                    $value = rtrim($rawValue, ' ');
                    $utf8 = iconv('CP850', 'UTF-8', $value);
                    if ($utf8 === false) {
                        throw new DbfReaderException(sprintf(
                            'Unable to convert CP850 value in DBF record %d, field %s.',
                            $recordIndex + 1,
                            $name,
                        ));
                    }
                    $values[$name] = $utf8;
                }

                yield $recordIndex + 1 => new DbfRecord(
                    $values['VIANUM'],
                    $values['CAP'],
                    $values['CITTA'],
                    $values['PR'],
                    $values['PARI_DISPA'],
                    $values['CIVICO_DA'],
                    $values['CIVICO_A'],
                );
            }

            $terminator = fread($stream, 1);
            if ($terminator !== '' && $terminator !== "\x1A") {
                throw new DbfReaderException('Expected DBF end-of-file marker 0x1A after the declared records.');
            }
        } catch (DbfReaderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new DbfReaderException('Unable to read DBF records: ' . $exception->getMessage(), 0, $exception);
        } finally {
            fclose($stream);
        }
    }

    private function readHeader(): void
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new DbfReaderException(sprintf('DBF file "%s" does not exist or is not readable.', $this->path));
        }

        $stream = @fopen($this->path, 'rb');
        if ($stream === false) {
            throw new DbfReaderException(sprintf('Cannot open DBF file "%s".', $this->path));
        }

        try {
            $header = fread($stream, 32);
            if ($header === false || strlen($header) !== 32) {
                throw new DbfReaderException('DBF header is truncated (expected 32 bytes).');
            }

            $this->dbfVersion = ord($header[0]);
            if ($this->dbfVersion !== 0x03) {
                throw new DbfReaderException(sprintf('Unsupported DBF version 0x%02X; expected dBase III/FoxBase+ 0x03.', $this->dbfVersion));
            }
            if (ord($header[29]) !== 0x02) {
                throw new DbfReaderException(sprintf('Unsupported DBF code page 0x%02X; expected OEM CP850 (0x02).', ord($header[29])));
            }

            $this->declaredRecordCount = unpack('Vcount', substr($header, 4, 4))['count'];
            $this->headerLength = unpack('vlength', substr($header, 8, 2))['length'];
            $this->recordLength = unpack('vlength', substr($header, 10, 2))['length'];

            if ($this->headerLength < 33 || $this->recordLength < 1) {
                throw new DbfReaderException('DBF header contains invalid header or record length.');
            }

            $descriptorBytes = $this->headerLength - 32;
            $descriptors = fread($stream, $descriptorBytes);
            if ($descriptors === false || strlen($descriptors) !== $descriptorBytes || $descriptors[$descriptorBytes - 1] !== "\x0D") {
                throw new DbfReaderException('DBF field descriptors are truncated or missing the 0x0D terminator.');
            }

            $offset = 0;
            for ($position = 0; $position < $descriptorBytes - 1; $position += 32) {
                if ($position + 32 > $descriptorBytes - 1) {
                    throw new DbfReaderException('DBF field descriptor is truncated.');
                }

                $descriptor = substr($descriptors, $position, 32);
                $name = strtoupper(rtrim(substr($descriptor, 0, 11), "\0 "));
                if ($name === '') {
                    throw new DbfReaderException('DBF contains a field with an empty name.');
                }
                if ($descriptor[11] !== 'C') {
                    throw new DbfReaderException(sprintf('DBF field %s has unsupported type %s; only Character fields are supported.', $name, $descriptor[11]));
                }

                $length = ord($descriptor[16]);
                if ($length < 1 || isset($this->fields[$name])) {
                    throw new DbfReaderException(sprintf('DBF field %s has invalid length or is duplicated.', $name));
                }
                $this->fields[$name] = ['offset' => $offset, 'length' => $length];
                $offset += $length;
            }

            if (32 + (($descriptorBytes - 1) / 32 * 32) + 1 !== $this->headerLength) {
                throw new DbfReaderException('DBF header length does not match the field descriptor layout.');
            }
            if ($offset + 1 !== $this->recordLength) {
                throw new DbfReaderException('DBF record length does not match its Character field widths.');
            }

            foreach (self::REQUIRED_FIELDS as $field) {
                if (!isset($this->fields[$field])) {
                    throw new DbfReaderException(sprintf('DBF is missing required field %s.', $field));
                }
            }
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function readExact($stream, int $length): string
    {
        $record = '';
        while (strlen($record) < $length) {
            $chunk = fread($stream, $length - strlen($record));
            if ($chunk === false || $chunk === '') {
                throw new DbfReaderException(sprintf('DBF record is truncated (expected %d bytes).', $length));
            }
            $record .= $chunk;
        }

        return $record;
    }
}
