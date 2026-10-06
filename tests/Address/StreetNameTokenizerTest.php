<?php

declare(strict_types=1);

namespace Normalizzatore\Tests\Address;

use Normalizzatore\Address\StreetNameTokenizer;
use Normalizzatore\Address\StreetType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StreetNameTokenizerTest extends TestCase
{
    public function testCanonicalizesAndSeparatesStreetTypeAndNominalTokens(): void
    {
        $street = (new StreetNameTokenizer())->tokenize("  Via   D`Annunzio   Sant'Andrea ");

        self::assertNotNull($street);
        self::assertSame("  Via   D`Annunzio   Sant'Andrea ", $street->sourceName);
        self::assertSame("VIA D'ANNUNZIO SANT'ANDREA", $street->canonicalName);
        self::assertSame(StreetType::VIA, $street->streetType);
        self::assertSame(["D'ANNUNZIO", "SANT'ANDREA"], $street->nominalTokens);
        self::assertSame(2, $street->tokenCount());
    }

    #[DataProvider('supportedTypes')]
    public function testRecognizesCorpusBackedStreetTypes(string $value, StreetType $expected): void
    {
        $street = (new StreetNameTokenizer())->tokenize($value . ' ROMA');

        self::assertNotNull($street);
        self::assertSame($expected, $street->streetType);
        self::assertSame(['ROMA'], $street->nominalTokens);
    }

    public static function supportedTypes(): iterable
    {
        yield 'via' => ['VIA', StreetType::VIA];
        yield 'localita' => ["LOCALITA'", StreetType::LOCALITA_APOSTROPHE];
        yield 'strada' => ['STRADA', StreetType::STRADA];
        yield 'contrada' => ['CONTRADA', StreetType::CONTRADA];
        yield 'vico' => ['VICO', StreetType::VICO];
        yield 'vicolo' => ['VICOLO', StreetType::VICOLO];
        yield 'piazza' => ['PIAZZA', StreetType::PIAZZA];
        yield 'traversa' => ['TRAVERSA', StreetType::TRAVERSA];
        yield 'viale' => ['VIALE', StreetType::VIALE];
        yield 'cascina' => ['CASCINA', StreetType::CASCINA];
        yield 'vocablo' => ['VOCABOLO', StreetType::VOCABOLO];
        yield 'cortile' => ['CORTILE', StreetType::CORTILE];
        yield 'calle' => ['CALLE', StreetType::CALLE];
    }

    #[DataProvider('unsupportedNames')]
    public function testReturnsNullForUnrecognizedOrIncompleteStreetNames(string $value): void
    {
        self::assertNull((new StreetNameTokenizer())->tokenize($value));
    }

    public static function unsupportedNames(): iterable
    {
        yield 'unknown type' => ['V. ROMA'];
        yield 'unapproved alias' => ['P.ZZA ROMA'];
        yield 'rare unlisted prefix' => ['GALLERIA ROMA'];
        yield 'type only' => ['VIA'];
        yield 'empty' => ['   '];
        yield 'bad UTF-8' => ["VIA CITT\xFF"];
    }
}
