<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Normalizzatore\Address\AddressInput;
use Normalizzatore\Application\AddressProcessorFactory;
use Normalizzatore\Csv\AddressProcessingResultSerializer;

/**
 * Normalizes one address using the same processing and output contract as the CSV command.
 *
 * @param string $address Original address text (the `vianum` value).
 * @param string $cap Original source CAP; it is verified independently and is
 *                     never used as a fallback for the normalized CAP.
 * @param string $citta Original city value.
 * @param string $provincia Original province value.
 * @param bool $fuzzy Enables conservative street-name abbreviation/one-token typo matching
 *                     for STREET_BASED addresses only. Exact directory evidence always wins;
 *                     fuzzy matching can abstain and never uses the source CAP to select a street.
 * @param bool $frazioni Enables exact/canonical frazione-to-comune resolution. It is independent
 *                       from $fuzzy; the optional catalog is loaded lazily only when consulted.
 *
 * @return array{
 *     via_normalizzata: string,
 *     civico_normalizzato: string,
 *     dettagli_normalizzati: string,
 *     cap_normalizzato: string,
 *     citta_normalizzata: string,
 *     provincia_normalizzata: string,
 *     stato_risoluzione: string,
 *     verifica_cap: string,
 *     correzioni_suggerite: string,
 *     diagnostica: string
 * }
 *         The ten appended CSV values, keyed in the same order as the CLI output.
 *         Normalized fields that are ambiguous, unverifiable, or missing are
 *         empty strings. `cap_normalizzato` is populated only for RESOLVED results.
 *         Fuzzy street corrections are proposed only when the normal CAP resolver resolves
 *         the selected directory street. The first eligible fuzzy call lazily builds a
 *         connection-local temporary catalog; the deterministic mode does not build it.
 *
 * @throws Throwable When the application database or another processing dependency fails.
 */
function normalizza(
    string $address,
    string $cap,
    string $citta,
    string $provincia,
    bool $fuzzy = false,
    bool $frazioni = false,
): array {
    static $processor = null;
    static $serializer = null;

    if ($processor === null || $serializer === null) {
        $processor = AddressProcessorFactory::create(dirname(__DIR__));
        $serializer = new AddressProcessingResultSerializer();
    }

    $values = $serializer->serialize($processor->process(new AddressInput($address, $cap, $citta, $provincia), $fuzzy, $frazioni));
    $result = array_combine(AddressProcessingResultSerializer::OUTPUT_COLUMNS, $values);
    if ($result === false) {
        throw new LogicException('Impossibile associare i valori normalizzati alle colonne pubbliche.');
    }

    return $result;
}
