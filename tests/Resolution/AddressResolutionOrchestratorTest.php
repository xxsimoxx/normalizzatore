<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Resolution;

use Normalizzatore\Address\AddressInput;
use Normalizzatore\Address\AddressCandidate;
use Normalizzatore\Address\AddressResolutionStrategy;
use Normalizzatore\Address\AddressStrategyClassifier;
use Normalizzatore\Address\AddressParser;
use Normalizzatore\Address\HouseNumber;
use Normalizzatore\City\CapizzatedCity;
use Normalizzatore\City\CapizzatedCityCatalog;
use Normalizzatore\Directory\AddressDirectoryInterface;
use Normalizzatore\Directory\DirectoryEntry;
use Normalizzatore\Directory\SqliteAddressDirectory;
use Normalizzatore\Directory\TerritorialEntry;
use Normalizzatore\Resolution\AddressResolutionDiagnostic;
use Normalizzatore\Resolution\AddressResolutionOrchestrator;
use Normalizzatore\Resolution\AddressResolutionStatus;
use Normalizzatore\Resolution\CapResolution;
use Normalizzatore\Resolution\CapResolutionBasis;
use Normalizzatore\Resolution\CapResolutionDiagnostic;
use Normalizzatore\Resolution\CapResolver;
use Normalizzatore\Resolution\CapResolutionStatus;
use Normalizzatore\Resolution\TerritorialResolutionStatus;
use Normalizzatore\Resolution\TerritorialResolver;
use Normalizzatore\Resolution\StreetCandidateResolution;
use Normalizzatore\Tests\Support\SqliteDirectoryFixture;
use PHPUnit\Framework\TestCase;

final class AddressResolutionOrchestratorTest extends TestCase
{
    public function testTerritorialPathResolvesFromCityOnlyAndPreservesTerritorialEvidence(): void
    {
        $directory = new FakeAddressDirectory(territorial: [
            new TerritorialEntry('OLBIA', 'SS', '07026', 4),
        ]);
        $orchestrator = $this->orchestrator($directory);

        $result = $orchestrator->resolve(new AddressInput('ignored street', '99999', 'Olbia', 'XX'));

        self::assertSame(AddressResolutionStrategy::TERRITORIAL, $result->strategy);
        self::assertSame(AddressResolutionStatus::RESOLVED, $result->status);
        self::assertSame('07026', $result->resolvedCap);
        self::assertSame(['07026'], $result->candidateCaps);
        self::assertSame(TerritorialResolutionStatus::RESOLVED, $result->territorialResolution?->status);
        self::assertSame('OLBIA', $result->territorialResolution?->evidence[0]->city);
        self::assertSame([['Olbia']], $directory->territorialCalls);
        self::assertSame([], $directory->streetCalls);
    }

    public function testTerritorialNoMatchMultipleCapsSpecialCapAndEmptyCityAreMapped(): void
    {
        $cases = [
            [[], '', AddressResolutionStatus::NO_MATCH, AddressResolutionDiagnostic::NO_TERRITORIAL_MATCH],
            [[new TerritorialEntry('FORLI\'-CESENA', 'FC', '47121', 2), new TerritorialEntry('FORLI\'-CESENA', 'FC', '47521', 3)], 'Forli-Cesena', AddressResolutionStatus::AMBIGUOUS, AddressResolutionDiagnostic::MULTIPLE_TERRITORIAL_CAPS],
            [[new TerritorialEntry('PAESE', 'TV', 'DISUS', 1)], 'Paese', AddressResolutionStatus::INDETERMINATE, AddressResolutionDiagnostic::SPECIAL_CAP_PRESENT],
        ];

        foreach ($cases as [$entries, $city, $expectedStatus, $diagnostic]) {
            $result = $this->orchestrator(new FakeAddressDirectory(territorial: $entries))
                ->resolve(new AddressInput('', '', $city, 'different'));
            self::assertSame($expectedStatus, $result->status);
            self::assertContains($diagnostic, $result->diagnostics);
            self::assertNull($result->resolvedCap);
        }

        $emptyCity = $this->orchestrator(new FakeAddressDirectory())
            ->resolve(new AddressInput('via qualunque', null, '', ''));
        self::assertSame(AddressResolutionStrategy::TERRITORIAL, $emptyCity->strategy);
        self::assertSame(AddressResolutionStatus::NO_MATCH, $emptyCity->status);
    }

    public function testStreetPathResolvesOneCandidateAndRetainsAllInterpretations(): void
    {
        $entries = [
            $this->streetEntry(1, 'VIA ROMA', '00100', 'T', '1', '30000'),
        ];
        $directory = new FakeAddressDirectory(streets: ['Via Roma' => $entries]);
        $result = $this->orchestrator($directory)->resolve(new AddressInput('Via Roma', '99999', 'Roma', 'RM'));

        self::assertSame(AddressResolutionStrategy::STREET_BASED, $result->strategy);
        self::assertSame(AddressResolutionStatus::RESOLVED, $result->status);
        self::assertSame('00100', $result->resolvedCap);
        self::assertCount(1, $result->streetCandidateResolutions);
        self::assertSame($entries, $result->streetCandidateResolutions[0]->directoryEntries);
        self::assertSame([], $directory->territorialCalls);
    }

    public function testStreetInputWithoutCivicNumberCanResolveWithinStreetPath(): void
    {
        $entry = $this->streetEntry(1, 'VIA ROMA', '00100', 'T', '1', '30000');
        $result = $this->orchestrator(new FakeAddressDirectory(streets: ['Via Roma' => [$entry]]))
            ->resolve(new AddressInput('Via Roma', '', 'Roma', 'RM'));

        self::assertSame(AddressResolutionStatus::RESOLVED, $result->status);
        self::assertSame('00100', $result->resolvedCap);
        self::assertSame(['00100'], $result->streetCandidateResolutions[0]->resolution->candidateCaps);
    }

    public function testStreetNoMatchAndEmptyParserOutputAreDistinguished(): void
    {
        $directory = new FakeAddressDirectory();
        $orchestrator = $this->orchestrator($directory);
        $noMatch = $orchestrator->resolve(new AddressInput('Via Roma', null, 'Roma', 'RM'));
        $noCandidates = $orchestrator->resolve(new AddressInput('', null, 'Roma', 'RM'));

        self::assertSame(AddressResolutionStatus::NO_MATCH, $noMatch->status);
        self::assertContains(AddressResolutionDiagnostic::NO_STREET_MATCH, $noMatch->diagnostics);
        self::assertSame(AddressResolutionStatus::NO_MATCH, $noCandidates->status);
        self::assertContains(AddressResolutionDiagnostic::NO_ADDRESS_CANDIDATES, $noCandidates->diagnostics);
        self::assertSame([], $directory->territorialCalls);
    }

    public function testDifferentStreetCandidatesWithSameCapResolveAndAreReported(): void
    {
        $directory = new FakeAddressDirectory(streets: [
            'Via Roma 15' => [$this->streetEntry(1, 'VIA ROMA 15', '00100', 'T', '1', '30000')],
            'Via Roma' => [$this->streetEntry(2, 'VIA ROMA', '00100', 'T', '1', '30000')],
        ]);
        $result = $this->orchestrator($directory)->resolve(new AddressInput('Via Roma 15', '99999', 'Roma', 'RM'));

        self::assertSame(AddressResolutionStatus::RESOLVED, $result->status);
        self::assertSame('00100', $result->resolvedCap);
        self::assertCount(2, $result->streetCandidateResolutions);
        self::assertSame('Via Roma 15', $result->streetCandidateResolutions[0]->candidate->streetName);
        self::assertSame('Via Roma', $result->streetCandidateResolutions[1]->candidate->streetName);
        self::assertContains(AddressResolutionDiagnostic::MULTIPLE_STREET_INTERPRETATIONS, $result->diagnostics);
    }

    public function testDifferentStreetCandidateCapsAreAmbiguousAndSorted(): void
    {
        $streetRows = [
            'Via Roma 15' => [$this->streetEntry(1, 'VIA ROMA 15', '00200', 'T', '1', '30000')],
            'Via Roma' => [$this->streetEntry(2, 'VIA ROMA', '00100', 'T', '1', '30000')],
        ];
        $forward = $this->orchestrator(new FakeAddressDirectory(streets: $streetRows))
            ->resolve(new AddressInput('Via Roma 15', null, 'Roma', 'RM'));

        self::assertSame(AddressResolutionStatus::AMBIGUOUS, $forward->status);
        self::assertSame(['00100', '00200'], $forward->candidateCaps);
    }

    public function testResolvedCandidateIsNotBlockedByAnotherCandidateWithoutMatch(): void
    {
        $directory = new FakeAddressDirectory(streets: [
            'Via Roma' => [$this->streetEntry(1, 'VIA ROMA', '00100', 'T', '1', '30000')],
        ]);
        $result = $this->orchestrator($directory)->resolve(new AddressInput('Via Roma 15', null, 'Roma', 'RM'));

        self::assertSame(AddressResolutionStatus::RESOLVED, $result->status);
        self::assertSame('00100', $result->resolvedCap);
        self::assertCount(2, $result->streetCandidateResolutions);
        self::assertSame([], $result->streetCandidateResolutions[0]->directoryEntries);
        self::assertNotEmpty($result->streetCandidateResolutions[1]->directoryEntries);
    }

    public function testIndeterminateAndAmbiguousCandidateEvidenceArePreserved(): void
    {
        $specialDirectory = new FakeAddressDirectory(streets: [
            'Via Roma' => [$this->streetEntry(1, 'VIA ROMA', 'DISUS', 'T', '1', '30000')],
        ]);
        $indeterminate = $this->orchestrator($specialDirectory)
            ->resolve(new AddressInput('Via Roma', null, 'Roma', 'RM'));
        self::assertSame(AddressResolutionStatus::INDETERMINATE, $indeterminate->status);
        self::assertContains(AddressResolutionDiagnostic::SPECIAL_CAP_PRESENT, $indeterminate->diagnostics);
        self::assertSame('DISUS', $indeterminate->streetCandidateResolutions[0]->directoryEntries[0]->cap);

        $ambiguousDirectory = new FakeAddressDirectory(streets: [
            'Via Roma' => [
                $this->streetEntry(1, 'VIA ROMA', '00100', 'T', '1', '30000'),
                $this->streetEntry(2, 'VIA ROMA', '00200', 'T', '1', '30000'),
            ],
        ]);
        $ambiguous = $this->orchestrator($ambiguousDirectory)
            ->resolve(new AddressInput('Via Roma', null, 'Roma', 'RM'));
        self::assertSame(AddressResolutionStatus::AMBIGUOUS, $ambiguous->status);
        self::assertContains(AddressResolutionDiagnostic::MULTIPLE_STREET_CAPS, $ambiguous->diagnostics);
    }

    public function testTerritorialResultDoesNotDependOnSourceCapOrProvince(): void
    {
        $orchestrator = $this->orchestrator(new FakeAddressDirectory(territorial: [
            new TerritorialEntry('OLBIA', 'SS', '07026', 1),
        ]));
        $first = $orchestrator->resolve(new AddressInput('', '07026', 'Olbia', 'SS'));
        $second = $orchestrator->resolve(new AddressInput('unrelated', '99999', 'Olbia', 'XX'));

        self::assertSame($first->status, $second->status);
        self::assertSame($first->candidateCaps, $second->candidateCaps);
        self::assertSame($first->resolvedCap, $second->resolvedCap);
    }

    public function testAmbiguousAndResolvedCandidatesRemainAmbiguousInEitherOrder(): void
    {
        $ambiguous = $this->candidateResult(
            'Via Roma',
            null,
            new CapResolution(
                CapResolutionStatus::AMBIGUOUS,
                CapResolutionBasis::NONE,
                ['10128', '10129'],
                [$this->streetEntry(1, 'VIA ROMA', '10128', 'T', '1', '19'), $this->streetEntry(2, 'VIA ROMA', '10129', 'T', '20', '40')],
                [],
                [CapResolutionDiagnostic::MULTIPLE_CAPS],
            ),
        );
        $resolved = $this->candidateResult(
            'Via Roma 15',
            '10128',
            $this->capResult(CapResolutionStatus::RESOLVED, ['10128'], [$this->streetEntry(3, 'VIA ROMA', '10128', 'T', '1', '19')]),
        );
        $forward = $this->aggregateCandidates([$ambiguous, $resolved]);
        $reverse = $this->aggregateCandidates([$resolved, $ambiguous]);

        $this->assertOrderIndependent($forward, $reverse, AddressResolutionStatus::AMBIGUOUS, ['10128', '10129']);
        self::assertNull($forward->resolvedCap);
        self::assertSame([$ambiguous, $resolved], $forward->streetCandidateResolutions);
        self::assertSame([$resolved, $ambiguous], $reverse->streetCandidateResolutions);
    }

    public function testAmbiguousAndResolvedThirdCapRetainAllThreeCandidatesInEitherOrder(): void
    {
        $ambiguous = $this->candidateResult(
            'Via Roma',
            null,
            new CapResolution(CapResolutionStatus::AMBIGUOUS, CapResolutionBasis::NONE, ['10128', '10129'], [
                $this->streetEntry(1, 'VIA ROMA', '10128', 'T', '1', '19'),
                $this->streetEntry(2, 'VIA ROMA', '10129', 'T', '20', '40'),
            ], [], [CapResolutionDiagnostic::MULTIPLE_CAPS]),
        );
        $resolved = $this->candidateResult(
            'Via Roma 50',
            '10130',
            $this->capResult(CapResolutionStatus::RESOLVED, ['10130'], [$this->streetEntry(3, 'VIA ROMA', '10130', 'T', '41', '60')]),
        );
        $forward = $this->aggregateCandidates([$ambiguous, $resolved]);
        $reverse = $this->aggregateCandidates([$resolved, $ambiguous]);

        $this->assertOrderIndependent($forward, $reverse, AddressResolutionStatus::AMBIGUOUS, ['10128', '10129', '10130']);
        self::assertCount(2, $forward->streetCandidateResolutions);
    }

    public function testResolvedAndIndeterminateCandidatesAreIndeterminateInEitherOrder(): void
    {
        $resolved = $this->candidateResult(
            'Corso',
            '24122',
            $this->capResult(CapResolutionStatus::RESOLVED, ['24122'], [$this->streetEntry(1, 'CORSO', '24122', 'T', '1', '30')]),
        );
        $indeterminate = $this->candidateResult(
            'Corso 31',
            null,
            new CapResolution(
                CapResolutionStatus::INDETERMINATE,
                CapResolutionBasis::NONE,
                ['24122'],
                [$this->streetEntry(2, 'CORSO 31', '24122', 'T', '31', '50')],
                [$this->streetEntry(3, 'CORSO 31', 'X', 'KM', '', '')],
                [CapResolutionDiagnostic::UNSUPPORTED_PARITY, CapResolutionDiagnostic::NON_ORDINARY_CAP],
            ),
        );
        $forward = $this->aggregateCandidates([$resolved, $indeterminate]);
        $reverse = $this->aggregateCandidates([$indeterminate, $resolved]);

        $this->assertOrderIndependent($forward, $reverse, AddressResolutionStatus::INDETERMINATE, ['24122']);
        self::assertNull($forward->resolvedCap);
        self::assertContains(CapResolutionDiagnostic::UNSUPPORTED_PARITY, $forward->streetCandidateResolutions[1]->resolution->diagnostics);
        self::assertSame([$resolved, $indeterminate], $forward->streetCandidateResolutions);
        self::assertSame([$indeterminate, $resolved], $reverse->streetCandidateResolutions);
    }

    public function testAmbiguousAndIndeterminateCandidatesRemainAmbiguousAndKeepIndeterminateEvidence(): void
    {
        $ambiguous = $this->candidateResult(
            'Via Moretto',
            null,
            new CapResolution(CapResolutionStatus::AMBIGUOUS, CapResolutionBasis::NONE, ['25121', '25122'], [
                $this->streetEntry(1, 'VIA MORETTO', '25121', 'P', '42', '30000'),
                $this->streetEntry(2, 'VIA MORETTO', '25122', 'P', '2', '42'),
            ], [], [CapResolutionDiagnostic::MULTIPLE_CAPS]),
        );
        $indeterminate = $this->candidateResult(
            'Via Moretto 42/A',
            '25123',
            new CapResolution(
                CapResolutionStatus::INDETERMINATE,
                CapResolutionBasis::NONE,
                ['25123'],
                [$this->streetEntry(3, 'VIA MORETTO', '25123', 'T', '1', '100')],
                [$this->streetEntry(4, 'VIA MORETTO', 'DISUS', 'R', '3/A', '3/A')],
                [CapResolutionDiagnostic::UNSUPPORTED_PARITY, CapResolutionDiagnostic::NON_ORDINARY_CAP],
            ),
        );
        $forward = $this->aggregateCandidates([$ambiguous, $indeterminate]);
        $reverse = $this->aggregateCandidates([$indeterminate, $ambiguous]);

        $this->assertOrderIndependent($forward, $reverse, AddressResolutionStatus::AMBIGUOUS, ['25121', '25122', '25123']);
        self::assertContains(AddressResolutionDiagnostic::INDETERMINATE_EVIDENCE, $forward->diagnostics);
        self::assertContains(CapResolutionDiagnostic::UNSUPPORTED_PARITY, $forward->streetCandidateResolutions[1]->resolution->diagnostics);
    }

    public function testResolvedAndNoMatchCandidatesResolveInEitherOrderAndKeepNoMatch(): void
    {
        $resolved = $this->candidateResult(
            'Via Roma',
            '00100',
            $this->capResult(CapResolutionStatus::RESOLVED, ['00100'], [$this->streetEntry(1, 'VIA ROMA', '00100', 'T', '1', '30000')]),
        );
        $noMatch = $this->candidateResult(
            'Via Roma 15',
            null,
            $this->capResult(CapResolutionStatus::NO_MATCH, []),
        );
        $forward = $this->aggregateCandidates([$resolved, $noMatch]);
        $reverse = $this->aggregateCandidates([$noMatch, $resolved]);

        $this->assertOrderIndependent($forward, $reverse, AddressResolutionStatus::RESOLVED, ['00100']);
        self::assertSame('00100', $forward->resolvedCap);
        self::assertSame(CapResolutionStatus::NO_MATCH, $forward->streetCandidateResolutions[1]->resolution->status);
        self::assertSame([$noMatch, $resolved], $reverse->streetCandidateResolutions);
    }

    public function testMultipleResolvedCandidatesWithSameCapResolveInEitherOrder(): void
    {
        $first = $this->candidateResult(
            'Via Roma',
            '00100',
            $this->capResult(CapResolutionStatus::RESOLVED, ['00100'], [$this->streetEntry(1, 'VIA ROMA', '00100', 'T', '1', '10')]),
        );
        $second = $this->candidateResult(
            'Via Roma 15',
            '00100',
            $this->capResult(CapResolutionStatus::RESOLVED, ['00100'], [$this->streetEntry(2, 'VIA ROMA', '00100', 'T', '11', '20')]),
        );
        $forward = $this->aggregateCandidates([$first, $second]);
        $reverse = $this->aggregateCandidates([$second, $first]);

        $this->assertOrderIndependent($forward, $reverse, AddressResolutionStatus::RESOLVED, ['00100']);
        self::assertSame('00100', $forward->resolvedCap);
        self::assertContains(AddressResolutionDiagnostic::MULTIPLE_STREET_INTERPRETATIONS, $forward->diagnostics);
        self::assertCount(2, $forward->streetCandidateResolutions);
    }

    public function testMultipleResolvedCandidatesWithDifferentCapsAreAmbiguousInEitherOrder(): void
    {
        $first = $this->candidateResult(
            'Via Roma',
            '00100',
            $this->capResult(CapResolutionStatus::RESOLVED, ['00100'], [$this->streetEntry(1, 'VIA ROMA', '00100', 'T', '1', '10')]),
        );
        $second = $this->candidateResult(
            'Via Roma 15',
            '00200',
            $this->capResult(CapResolutionStatus::RESOLVED, ['00200'], [$this->streetEntry(2, 'VIA ROMA', '00200', 'T', '11', '20')]),
        );
        $forward = $this->aggregateCandidates([$first, $second]);
        $reverse = $this->aggregateCandidates([$second, $first]);

        $this->assertOrderIndependent($forward, $reverse, AddressResolutionStatus::AMBIGUOUS, ['00100', '00200']);
        self::assertNull($forward->resolvedCap);
        self::assertContains(AddressResolutionDiagnostic::MULTIPLE_STREET_CAPS, $forward->diagnostics);
    }

    public function testOperationallyEmptyInputReturnsNoMatchWithoutDirectoryLookupRegardlessOfSourceCap(): void
    {
        $directory = new FakeAddressDirectory(territorial: [
            new TerritorialEntry('', '', '00100', 1),
        ]);
        $orchestrator = $this->orchestrator($directory);

        foreach ([null, '', '00100'] as $sourceCap) {
            $result = $orchestrator->resolve(new AddressInput(" \t", $sourceCap, null, ''));
            self::assertSame(AddressResolutionStatus::NO_MATCH, $result->status);
            self::assertContains(AddressResolutionDiagnostic::EMPTY_ADDRESS_INPUT, $result->diagnostics);
        }

        self::assertSame([], $directory->territorialCalls);
        self::assertSame([], $directory->streetCalls);
    }

    public function testSyntheticSqliteIntegrationSelectsBothPathsAndReusesDirectoryInstance(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'normalizzatore-orchestrator-');
        self::assertNotFalse($path);
        SqliteDirectoryFixture::create($path, [
            SqliteDirectoryFixture::row('VIA ROMA', '00100', 'ROMA', 'RM', 'T', '1', '30000'),
            SqliteDirectoryFixture::row('VIA OLBIA', '07026', 'OLBIA', 'SS', 'T', '1', '30000'),
        ]);

        try {
            $directory = new SqliteAddressDirectory($path);
            $orchestrator = $this->orchestrator($directory);
            $street = $orchestrator->resolve(new AddressInput('Via Roma 15', '99999', 'Roma', 'RM'));

            $pdoProperty = new \ReflectionProperty(SqliteAddressDirectory::class, 'pdo');
            $pdo = $pdoProperty->getValue($directory);
            self::assertSame(0, (int) $pdo->query(
                "SELECT COUNT(*) FROM sqlite_temp_master WHERE name = 'territorial_directory_entries'",
            )->fetchColumn());

            $territorial = $orchestrator->resolve(new AddressInput('ignored', null, 'Olbia', 'XX'));

            self::assertSame(AddressResolutionStrategy::STREET_BASED, $street->strategy);
            self::assertSame(AddressResolutionStatus::RESOLVED, $street->status);
            self::assertSame('00100', $street->resolvedCap);
            self::assertSame(AddressResolutionStrategy::TERRITORIAL, $territorial->strategy);
            self::assertSame(AddressResolutionStatus::RESOLVED, $territorial->status);
            self::assertSame('07026', $territorial->resolvedCap);
            self::assertSame(1, $territorial->territorialResolution?->evidence[0]->recordCount);
            self::assertSame(1, (int) $pdo->query(
                "SELECT COUNT(*) FROM sqlite_temp_master WHERE name = 'territorial_directory_entries'",
            )->fetchColumn());
        } finally {
            @unlink($path);
        }
    }

    private function orchestrator(AddressDirectoryInterface $directory): AddressResolutionOrchestrator
    {
        $catalog = new CapizzatedCityCatalog([
            new CapizzatedCity('Roma', 'RM'),
            new CapizzatedCity('Milano', 'MI'),
        ]);

        return new AddressResolutionOrchestrator(
            new AddressStrategyClassifier($catalog),
            new AddressParser(),
            $directory,
            new CapResolver(),
            new TerritorialResolver(),
        );
    }

    private function streetEntry(int $id, string $street, string $cap, string $parity, string $from, string $to): DirectoryEntry
    {
        return new DirectoryEntry($id, $street, $cap, 'ROMA', 'RM', $parity, $from, $to);
    }

    private function candidateResult(string $street, ?string $number, CapResolution $resolution): StreetCandidateResolution
    {
        $candidate = new AddressCandidate($street, $number === null ? null : new HouseNumber($number), '');

        return new StreetCandidateResolution($candidate, [...$resolution->applicableEntries, ...$resolution->unsupportedEntries], $resolution);
    }

    /** @param list<string> $caps
     *  @param list<DirectoryEntry> $entries
     */
    private function capResult(CapResolutionStatus $status, array $caps, array $entries = []): CapResolution
    {
        return new CapResolution($status, $status === CapResolutionStatus::RESOLVED ? CapResolutionBasis::CIVIC_RANGE : CapResolutionBasis::NONE, $caps, $entries, [], []);
    }

    /** @param list<StreetCandidateResolution> $candidateResults */
    private function aggregateCandidates(array $candidateResults): \Normalizzatore\Resolution\AddressResolution
    {
        $method = new \ReflectionMethod(AddressResolutionOrchestrator::class, 'streetResult');

        return $method->invoke($this->orchestrator(new FakeAddressDirectory()), $candidateResults);
    }

    /** @param list<string> $expectedCaps */
    private function assertOrderIndependent(
        \Normalizzatore\Resolution\AddressResolution $forward,
        \Normalizzatore\Resolution\AddressResolution $reverse,
        AddressResolutionStatus $expectedStatus,
        array $expectedCaps,
    ): void {
        self::assertSame($expectedStatus, $forward->status);
        self::assertSame($expectedStatus, $reverse->status);
        self::assertSame($expectedCaps, $forward->candidateCaps);
        self::assertSame($expectedCaps, $reverse->candidateCaps);
        self::assertSame($forward->resolvedCap, $reverse->resolvedCap);
        self::assertSame($forward->diagnostics, $reverse->diagnostics);
    }
}

/** Small strict fake keeping orchestration unit tests independent from SQLite. */
final class FakeAddressDirectory implements AddressDirectoryInterface
{
    /** @var list<list<string>> */
    public array $territorialCalls = [];

    /** @var list<array{0: string, 1: string, 2: string}> */
    public array $streetCalls = [];

    /**
     * @param array<string, list<DirectoryEntry>> $streets keyed by parser streetName
     * @param list<TerritorialEntry> $territorial
     */
    public function __construct(
        private readonly array $streets = [],
        private readonly array $territorial = [],
    ) {
    }

    public function findByStreetCityProvince(string $street, string $city, string $province): array
    {
        $this->streetCalls[] = [$street, $city, $province];

        return $this->streets[$street] ?? [];
    }

    public function findTerritorialEntries(string $city): array
    {
        $this->territorialCalls[] = [$city];

        return $this->territorial;
    }
}
