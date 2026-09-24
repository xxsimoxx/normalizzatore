<?php

declare(strict_types=1);

namespace Normalizzatore\Tests;

use Normalizzatore\Foundation;
use PHPUnit\Framework\TestCase;

final class FoundationTest extends TestCase
{
    public function testProjectClassIsAutoloadedAndTestSuiteRuns(): void
    {
        self::assertTrue((new Foundation())->isReady());
    }
}
