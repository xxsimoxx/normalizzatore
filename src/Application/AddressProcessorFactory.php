<?php

declare(strict_types=1);

namespace Normalizzatore\Application;

use Normalizzatore\Address\AddressParser;
use Normalizzatore\Address\AddressStrategyClassifier;
use Normalizzatore\City\CapizzatedCityCatalog;
use Normalizzatore\Directory\SqliteAddressDirectory;
use Normalizzatore\Resolution\FuzzyStreetMatcher;
use Normalizzatore\Normalization\AddressFieldNormalizer;
use Normalizzatore\Resolution\AddressResolutionOrchestrator;
use Normalizzatore\Resolution\CapResolver;
use Normalizzatore\Resolution\TerritorialResolver;
use Normalizzatore\Verification\SourceCapVerifier;

/** Creates the shared application composition for CLI and public PHP entry points. */
final class AddressProcessorFactory
{
    public static function create(string $projectRoot): AddressProcessor
    {
        $projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $catalog = CapizzatedCityCatalog::fromTsvFile($projectRoot . '/resources/capizzated-cities.tsv');
        $directory = new SqliteAddressDirectory($projectRoot . '/var/archi_cap.sqlite');
        $orchestrator = new AddressResolutionOrchestrator(
            new AddressStrategyClassifier($catalog),
            new AddressParser(),
            $directory,
            new CapResolver(),
            new TerritorialResolver(),
            $directory,
            new FuzzyStreetMatcher(),
        );

        return new AddressProcessor($orchestrator, new SourceCapVerifier(), new AddressFieldNormalizer());
    }
}
