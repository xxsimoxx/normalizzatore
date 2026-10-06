<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Application;

use Normalizzatore\Address\AddressInput;
use Normalizzatore\Application\AddressProcessorFactory;
use Normalizzatore\Csv\AddressProcessingResultSerializer;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Throwable;

require_once dirname(__DIR__, 2) . '/lib/normalizza.php';

final class NormalizzaApiTest extends TestCase
{
    public function testReturnsTheTenCliColumnsForAResolvedRealAddress(): void
    {
        $result = normalizza('VIA DEL GRIFO 4/INT 8', '36071', 'ARZIGNANO', 'VI');

        self::assertSame(AddressProcessingResultSerializer::OUTPUT_COLUMNS, array_keys($result));
        self::assertSame('VIA DEL GRIFO', $result['via_normalizzata']);
        self::assertSame('4', $result['civico_normalizzato']);
        self::assertSame('/INT 8', $result['dettagli_normalizzati']);
        self::assertSame('36071', $result['cap_normalizzato']);
        self::assertSame('RESOLVED', $result['stato_risoluzione']);
        self::assertSame('MATCH', $result['verifica_cap']);
    }

    public function testPublicArrayMatchesTheCliSerializerForAllTenValues(): void
    {
        $input = new AddressInput('VIA DEL GRIFO 4/INT 8', '36000', 'ARZIGNANO', 'VI');
        $expected = (new AddressProcessingResultSerializer())->serialize(
            AddressProcessorFactory::create(dirname(__DIR__, 2))->process($input),
        );
        $expected = array_combine(AddressProcessingResultSerializer::OUTPUT_COLUMNS, $expected);

        self::assertSame($expected, normalizza($input->vianum, $input->cap, $input->city, $input->province));
        self::assertSame('MISMATCH', $expected['verifica_cap']);
        self::assertStringContainsString('CAP:"36000"->"36071"', $expected['correzioni_suggerite']);
    }

    public function testNoMatchDoesNotUseTheSourceCapAsNormalizedCap(): void
    {
        $result = normalizza('VIA INESISTENTE 1', '00100', 'CITTA IMPOSSIBILE', 'ZZ');

        self::assertSame('NO_MATCH', $result['stato_risoluzione']);
        self::assertSame('', $result['cap_normalizzato']);
        self::assertSame('UNVERIFIABLE', $result['verifica_cap']);
    }

    public function testEmptyInputReturnsTheSameTenStringColumnsAsTheCliContract(): void
    {
        $result = normalizza('', '', '', '');

        self::assertSame(AddressProcessingResultSerializer::OUTPUT_COLUMNS, array_keys($result));
        self::assertCount(10, $result);
        self::assertSame('NO_MATCH', $result['stato_risoluzione']);
        self::assertSame('SOURCE_MISSING', $result['verifica_cap']);
        foreach (array_slice($result, 0, 6) as $value) {
            self::assertSame('', $value);
        }
    }

    public function testFuzzyModeKeepsDeterministicExactMatchesAndUsesThePublicContract(): void
    {
        $deterministic = normalizza('VIA DEL GRIFO 4/INT 8', '36071', 'ARZIGNANO', 'VI');
        $fuzzyEnabled = normalizza('VIA DEL GRIFO 4/INT 8', '36071', 'ARZIGNANO', 'VI', true);

        self::assertSame($deterministic, $fuzzyEnabled);
        self::assertCount(10, $fuzzyEnabled);
    }

    public function testFuzzyModeCorrectsARealTypoOnlyAfterNormalDirectoryResolution(): void
    {
        $input = ['VIA CAPUCCINA 181/G', '30172', 'VENEZIA', 'VE'];
        $deterministicBefore = normalizza(...$input);
        $result = normalizza($input[0], $input[1], $input[2], $input[3], true);
        $fuzzyAgain = normalizza($input[0], $input[1], $input[2], $input[3], true);
        $deterministicAfter = normalizza(...$input);

        self::assertSame($deterministicBefore, $deterministicAfter);
        self::assertSame($result, $fuzzyAgain);
        self::assertSame(AddressProcessingResultSerializer::OUTPUT_COLUMNS, array_keys($result));
        self::assertSame('VIA CAPPUCCINA', $result['via_normalizzata']);
        self::assertSame('181', $result['civico_normalizzato']);
        self::assertSame('/G', $result['dettagli_normalizzati']);
        self::assertSame('30172', $result['cap_normalizzato']);
        self::assertSame('RESOLVED', $result['stato_risoluzione']);
        self::assertStringContainsString('fuzzy_typo_correction', $result['correzioni_suggerite']);
        self::assertStringContainsString('RESOLUTION:fuzzy_typo_match', $result['diagnostica']);
    }

    #[RunInSeparateProcess]
    public function testUsesProjectPathsIndependentlyOfCurrentWorkingDirectory(): void
    {
        $originalDirectory = getcwd();
        self::assertNotFalse($originalDirectory);

        try {
            self::assertTrue(chdir(sys_get_temp_dir()));
            $result = normalizza('VIA DEL GRIFO 4/INT 8', '36071', 'ARZIGNANO', 'VI');
            self::assertSame('36071', $result['cap_normalizzato']);
            self::assertSame('VIA DEL GRIFO', $result['via_normalizzata']);
        } finally {
            chdir($originalDirectory);
        }
    }

    public function testRepeatedCallsReuseStateWithoutLeakingResultsBetweenAddresses(): void
    {
        $first = normalizza('VIA DEL GRIFO 4/INT 8', '36071', 'ARZIGNANO', 'VI');
        $second = normalizza('VIA INESISTENTE 1', '00100', 'CITTA IMPOSSIBILE', 'ZZ');
        $third = normalizza('VIA DEL GRIFO 4/INT 8', '36071', 'ARZIGNANO', 'VI');

        self::assertSame($first, $third);
        self::assertSame('RESOLVED', $first['stato_risoluzione']);
        self::assertSame('NO_MATCH', $second['stato_risoluzione']);
        self::assertSame('', $second['cap_normalizzato']);
        self::assertSame('', $second['correzioni_suggerite']);
        self::assertStringNotContainsString('VIA DEL GRIFO', $second['diagnostica']);
    }

    public function testDoesNotWriteToOutputStreams(): void
    {
        ob_start();
        try {
            normalizza('VIA DEL GRIFO 4/INT 8', '36071', 'ARZIGNANO', 'VI');
            $output = ob_get_contents();
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
        ob_end_clean();

        self::assertSame('', $output);
    }
}
