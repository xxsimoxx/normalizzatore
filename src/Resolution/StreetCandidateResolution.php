<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use InvalidArgumentException;
use Normalizzatore\Address\AddressCandidate;
use Normalizzatore\Directory\DirectoryEntry;

/** The lookup and CAP-resolution evidence for one parser interpretation. */
final readonly class StreetCandidateResolution
{
    /**
     * @param list<DirectoryEntry> $directoryEntries
     */
    public function __construct(
        public AddressCandidate $candidate,
        public array $directoryEntries,
        public CapResolution $resolution,
    ) {
        if (!array_is_list($directoryEntries)) {
            throw new InvalidArgumentException('Street candidate directory evidence must be a list.');
        }
        foreach ($directoryEntries as $entry) {
            if (!$entry instanceof DirectoryEntry) {
                throw new InvalidArgumentException('Street candidate directory evidence must contain DirectoryEntry values.');
            }
        }
    }
}
