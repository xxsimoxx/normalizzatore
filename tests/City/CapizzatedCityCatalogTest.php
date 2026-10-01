<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\City;

use Normalizzatore\City\CapizzatedCity;
use Normalizzatore\City\CapizzatedCityCatalog;
use Normalizzatore\Address\AddressInput;
use Normalizzatore\Address\AddressResolutionStrategy;
use Normalizzatore\Address\AddressStrategyClassifier;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

final class CapizzatedCityCatalogTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                chmod($path, 0600);
                unlink($path);
            }
        }
    }

    private function catalog(): CapizzatedCityCatalog
    {
        return CapizzatedCityCatalog::fromTsvFile(__DIR__ . '/../../resources/capizzated-cities.tsv');
    }

    public function testLoadsAllFortyTwoCitiesAndRetainsProvinceAsMetadata(): void
    {
        $catalog = $this->catalog();

        self::assertCount(42, $catalog->all());
        self::assertSame('CA', $catalog->find('Cagliari')?->provinceMetadata);
        self::assertSame('FORLI\'', $catalog->find("  forli' ")?->name);
    }

    public function testMembershipUsesCityNameAndConservativeWhitespaceAndCaseNormalization(): void
    {
        $catalog = $this->catalog();

        self::assertTrue($catalog->isCapizzated('  la   spezia  '));
        self::assertTrue($catalog->isCapizzated('reggio' . "\t" . 'calabria'));
        self::assertFalse($catalog->isCapizzated('La Spezia, SP'));
        self::assertFalse($catalog->isCapizzated('Olbia'));
    }

    public function testSupportedOrthographicVariantsUseTheSameCatalogEntry(): void
    {
        $catalog = $this->catalog();

        self::assertSame('FORLI\'', $catalog->find('FORLÌ')?->name);
        self::assertSame('FORLI\'', $catalog->find('Forli’')?->name);
        self::assertTrue($catalog->isCapizzated("FORLI'"));
        $classifier = new AddressStrategyClassifier($catalog);
        self::assertSame(
            $classifier->classify(new AddressInput('Via Roma', null, 'FORLÌ', null)),
            $classifier->classify(new AddressInput('Via Roma', null, "FORLI'", null)),
        );
        self::assertSame(AddressResolutionStrategy::STREET_BASED, $classifier->classify(new AddressInput('Via Roma', null, 'FORLÌ', null)));
    }

    public function testMestreAndVeneziaRemainDistinctEntries(): void
    {
        $catalog = $this->catalog();

        self::assertTrue($catalog->isCapizzated('MESTRE'));
        self::assertTrue($catalog->isCapizzated('VENEZIA'));
        self::assertSame('MESTRE', $catalog->find('mestre')?->name);
        self::assertSame('VENEZIA', $catalog->find('venezia')?->name);
        self::assertNotSame($catalog->find('mestre'), $catalog->find('venezia'));
    }

    public function testMembershipDoesNotDependOnProvinceMetadata(): void
    {
        $catalog = new CapizzatedCityCatalog([new CapizzatedCity('CAGLIARI', 'XX')]);

        self::assertTrue($catalog->isCapizzated('Cagliari'));
        self::assertSame('XX', $catalog->find('Cagliari')?->provinceMetadata);
    }

    public function testMissingOrNonFilePathIsRejected(): void
    {
        $this->expectException(RuntimeException::class);

        CapizzatedCityCatalog::fromTsvFile(__DIR__ . '/missing-cities.tsv');
    }

    public function testDirectoryPathIsRejectedAsUnreadableInputFile(): void
    {
        $this->expectException(RuntimeException::class);

        CapizzatedCityCatalog::fromTsvFile(__DIR__);
    }

    public function testUnreadableFileIsRejected(): void
    {
        $path = $this->temporaryTsv("ROMA\tRM\n");
        chmod($path, 0000);
        if (is_readable($path)) {
            self::markTestSkipped('The current user can read permission-restricted files.');
        }

        $this->expectException(RuntimeException::class);
        CapizzatedCityCatalog::fromTsvFile($path);
    }

    public function testStructurallyMalformedRowIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        CapizzatedCityCatalog::fromTsvFile($this->temporaryTsv("ROMA\tRM\textra\n"));
    }

    public function testEmptyCityIsRejected(): void
    {
        $this->expectException(UnexpectedValueException::class);

        CapizzatedCityCatalog::fromTsvFile($this->temporaryTsv("\tRM\n"));
    }

    public function testDuplicateAfterConservativeNormalizationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CapizzatedCityCatalog::fromTsvFile($this->temporaryTsv("ROMA\tRM\n  roma \tXX\n"));
    }

    private function temporaryTsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'capizzated-cities-');
        if ($path === false) {
            self::fail('Unable to create temporary TSV fixture.');
        }

        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
