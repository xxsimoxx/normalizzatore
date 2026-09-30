<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Address;

use Normalizzatore\Address\AddressInput;
use Normalizzatore\Address\AddressResolutionStrategy;
use Normalizzatore\Address\AddressStrategyClassifier;
use Normalizzatore\City\CapizzatedCityCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddressStrategyClassifierTest extends TestCase
{
    private AddressStrategyClassifier $classifier;

    protected function setUp(): void
    {
        $catalog = CapizzatedCityCatalog::fromTsvFile(__DIR__ . '/../../resources/capizzated-cities.tsv');
        $this->classifier = new AddressStrategyClassifier($catalog);
    }

    #[DataProvider('capizzatedCityCases')]
    public function testCapizzatedCitiesUseStreetBasedStrategy(string $city): void
    {
        self::assertSame(
            AddressResolutionStrategy::STREET_BASED,
            $this->classifier->classify($this->input(city: $city)),
        );
    }

    public static function capizzatedCityCases(): iterable
    {
        yield 'Rome' => ['Roma'];
        yield 'Milan' => ['Milano'];
        yield 'Cagliari' => ['Cagliari'];
        yield 'Mestre' => ['Mestre'];
        yield 'Venice' => ['Venezia'];
    }

    #[DataProvider('nonCapizzatedCityCases')]
    public function testNonCapizzatedCitiesUseTerritorialStrategy(string $city): void
    {
        self::assertSame(
            AddressResolutionStrategy::TERRITORIAL,
            $this->classifier->classify($this->input(city: $city)),
        );
    }

    public static function nonCapizzatedCityCases(): iterable
    {
        yield 'Olbia' => ['OLBIA'];
        yield 'Dorgali' => ['DORGALI'];
        yield 'San Teodoro' => ['SAN TEODORO'];
    }

    #[DataProvider('normalizedCityCases')]
    public function testCityMatchingUsesCatalogNormalization(string $city): void
    {
        self::assertSame(
            AddressResolutionStrategy::STREET_BASED,
            $this->classifier->classify($this->input(city: $city)),
        );
    }

    public static function normalizedCityCases(): iterable
    {
        yield 'trim and lowercase' => [' roma '];
        yield 'collapse whitespace' => ['Reggio   Emilia'];
    }

    public function testProvinceDoesNotAffectClassification(): void
    {
        foreach (['CA', 'XX', ''] as $province) {
            self::assertSame(
                AddressResolutionStrategy::STREET_BASED,
                $this->classifier->classify($this->input(city: 'Cagliari', province: $province)),
            );
        }
    }

    public function testCapAndStreetDoNotAffectClassification(): void
    {
        $inputs = [
            $this->input(vianum: 'Via Roma 15', cap: '00118', city: 'Roma', province: 'RM'),
            $this->input(vianum: 'Via diversa 99/A', cap: '99999', city: 'Roma', province: 'XX'),
            $this->input(vianum: '', cap: '', city: 'Roma', province: ''),
        ];

        foreach ($inputs as $input) {
            self::assertSame(AddressResolutionStrategy::STREET_BASED, $this->classifier->classify($input));
        }
    }

    public function testMestreAndVeneziaUseTheSameStrategyWithoutBeingAliased(): void
    {
        $mestre = $this->classifier->classify($this->input(city: 'Mestre'));
        $venezia = $this->classifier->classify($this->input(city: 'Venezia'));

        self::assertSame(AddressResolutionStrategy::STREET_BASED, $mestre);
        self::assertSame(AddressResolutionStrategy::STREET_BASED, $venezia);
        self::assertSame($mestre, $venezia);
    }

    public function testMissingCityUsesTerritorialStrategy(): void
    {
        self::assertSame(
            AddressResolutionStrategy::TERRITORIAL,
            $this->classifier->classify($this->input(city: null)),
        );
    }

    private function input(
        string $vianum = '',
        ?string $cap = null,
        ?string $city = null,
        ?string $province = null,
    ): AddressInput {
        return new AddressInput($vianum, $cap, $city, $province);
    }
}
