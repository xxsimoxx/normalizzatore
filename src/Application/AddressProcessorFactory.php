<?php

declare(strict_types=1);

namespace Normalizzatore\Application;

use Normalizzatore\Address\AddressParser;
use Normalizzatore\Address\AddressStrategyClassifier;
use Normalizzatore\Address\StreetNameTokenizer;
use Normalizzatore\City\CapizzatedCityCatalog;
use Normalizzatore\City\FuzzyCityResolver;
use Normalizzatore\Directory\SqliteAddressDirectory;
use Normalizzatore\Resolution\FuzzyStreetMatcher;
use Normalizzatore\Normalization\AddressFieldNormalizer;
use Normalizzatore\Resolution\AddressResolutionOrchestrator;
use Normalizzatore\Resolution\CapResolver;
use Normalizzatore\Resolution\TerritorialResolver;
use Normalizzatore\Verification\SourceCapVerifier;
use Normalizzatore\Frazione\FrazioneCatalog;
use Normalizzatore\Frazione\FrazioneResolver;

/** Creates the shared application composition for CLI and public PHP entry points. */
final class AddressProcessorFactory
{
    public static function create(string $projectRoot): AddressProcessor
    {
        $projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $catalog = CapizzatedCityCatalog::fromTsvFile($projectRoot . '/resources/capizzated-cities.tsv');
        $directory = new SqliteAddressDirectory($projectRoot . '/var/archi_cap.sqlite');
        $frazioneCatalog = new FrazioneCatalog($projectRoot . '/resources/frazioni.tsv');
        $orchestrator = new AddressResolutionOrchestrator(
            strategyClassifier: new AddressStrategyClassifier($catalog),
            addressParser: new AddressParser(),
            directory: $directory,
            capResolver: new CapResolver(),
            territorialResolver: new TerritorialResolver(),
            fuzzyCandidateProvider: $directory,
            fuzzyStreetMatcher: new FuzzyStreetMatcher(),
            streetNameTokenizer: new StreetNameTokenizer(),
            fuzzyCityCandidateProvider: $directory,
            fuzzyCityResolver: new FuzzyCityResolver(),
            territorialStreetRecoveryProvider: $directory,
            frazioneCatalog: $frazioneCatalog,
            frazioneResolver: new FrazioneResolver(),
            fuzzyFrazioneCandidateProvider: $frazioneCatalog,
        );

        return new AddressProcessor($orchestrator, new SourceCapVerifier(), new AddressFieldNormalizer());
    }
}
