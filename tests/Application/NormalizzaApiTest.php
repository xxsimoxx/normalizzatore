<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Application;

use LogicException;
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

    public function testFuzzyModeFailsClearlyInsteadOfSilentlyUsingDeterministicMode(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('modalità fuzzy non è ancora disponibile');

        normalizza('VIA ROMA 1', '00100', 'ROMA', 'RM', true);
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
