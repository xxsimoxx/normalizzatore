<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Normalizzatore\Address\AddressInput;
use Normalizzatore\Application\AddressProcessorFactory;
use Normalizzatore\Csv\AddressProcessingResultSerializer;

/**
 * Deterministically normalizes one address using the same processing and output
 * contract as the CSV command.
 *
 * @param string $address Original address text (the `vianum` value).
 * @param string $cap Original source CAP; it is verified independently and is
 *                     never used as a fallback for the normalized CAP.
 * @param string $citta Original city value.
 * @param string $provincia Original province value.
 * @param bool $fuzzy Reserved for a future fuzzy mode. It must currently be false.
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
 *
 * @throws LogicException When `$fuzzy` is true because fuzzy processing is not yet available.
 * @throws Throwable When the application database or another processing dependency fails.
 */
function normalizza(
    string $address,
    string $cap,
    string $citta,
    string $provincia,
    bool $fuzzy = false,
): array {
    if ($fuzzy) {
        throw new LogicException('La modalità fuzzy non è ancora disponibile.');
    }

    static $processor = null;
    static $serializer = null;

    if ($processor === null || $serializer === null) {
        $processor = AddressProcessorFactory::create(dirname(__DIR__));
        $serializer = new AddressProcessingResultSerializer();
    }

    $values = $serializer->serialize($processor->process(new AddressInput($address, $cap, $citta, $provincia)));
    $result = array_combine(AddressProcessingResultSerializer::OUTPUT_COLUMNS, $values);
    if ($result === false) {
        throw new LogicException('Impossibile associare i valori normalizzati alle colonne pubbliche.');
    }

    return $result;
}
