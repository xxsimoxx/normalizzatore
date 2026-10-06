<?php

declare(strict_types=1);

namespace Normalizzatore\Resolution;

use InvalidArgumentException;
use Normalizzatore\Address\TokenizedStreetName;

/** Nominal evidence for one possible or selected fuzzy street-name candidate. */
final readonly class FuzzyStreetMatchEvidence
{
    public function __construct(
        public TokenizedStreetName $source,
        public TokenizedStreetName $candidate,
        public FuzzyStreetMatchKind $kind,
        /** Zero-based position among nominal tokens. */
        public int $tokenPosition,
        public string $sourceToken,
        public string $candidateToken,
        public ?int $distance = null,
        public ?float $normalizedDistance = null,
        public ?int $secondBestDistance = null,
        public ?int $margin = null,
    ) {
        if ($tokenPosition < 0
            || !isset($source->nominalTokens[$tokenPosition], $candidate->nominalTokens[$tokenPosition])
            || $source->nominalTokens[$tokenPosition] !== $sourceToken
            || $candidate->nominalTokens[$tokenPosition] !== $candidateToken) {
            throw new InvalidArgumentException('Fuzzy evidence token position and token values must match the street names.');
        }
        if ($source->streetType !== $candidate->streetType) {
            throw new InvalidArgumentException('Fuzzy evidence must preserve the street type.');
        }
        if ($source->tokenCount() !== $candidate->tokenCount()) {
            throw new InvalidArgumentException('Fuzzy evidence must preserve the nominal token count.');
        }
        foreach ($source->nominalTokens as $position => $token) {
            if ($position !== $tokenPosition && $token !== $candidate->nominalTokens[$position]) {
                throw new InvalidArgumentException('Fuzzy evidence may differ at only the reported token position.');
            }
        }

        if ($kind === FuzzyStreetMatchKind::ABBREVIATION) {
            if (preg_match('/\A\p{L}\.\z/u', $sourceToken) !== 1
                || $candidateToken === $sourceToken
                || mb_strlen($candidateToken, 'UTF-8') < 2
                || mb_strtoupper(mb_substr($candidateToken, 0, 1, 'UTF-8'), 'UTF-8') !== mb_substr($sourceToken, 0, 1, 'UTF-8')
                || $distance !== null || $normalizedDistance !== null || $secondBestDistance !== null || $margin !== null) {
                throw new InvalidArgumentException('Abbreviation evidence must describe one dotted initial expansion without edit distances.');
            }
        } else {
            if ($sourceToken === $candidateToken
                || $distance === null || $distance < 1
                || $normalizedDistance === null || $normalizedDistance <= 0.0 || $normalizedDistance > 1.0
                || (($secondBestDistance === null) !== ($margin === null))
                || ($secondBestDistance !== null && ($secondBestDistance < $distance || $margin < 0 || $margin !== $secondBestDistance - $distance))) {
                throw new InvalidArgumentException('Typo evidence must have consistent positive distance and optional second-best margin.');
            }
            $expectedNormalizedDistance = $distance / max(
                mb_strlen($sourceToken, 'UTF-8'),
                mb_strlen($candidateToken, 'UTF-8'),
            );
            if (abs($normalizedDistance - $expectedNormalizedDistance) > 1.0e-12) {
                throw new InvalidArgumentException('Normalized typo distance must be distance divided by the longer token length.');
            }
        }
    }
}
