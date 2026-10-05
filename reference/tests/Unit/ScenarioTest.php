<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Core\Scenario;
use PHPUnit\Framework\TestCase;

final class ScenarioTest extends TestCase
{
    public function testMagicNumbersMapToScenarios(): void
    {
        self::assertSame(Scenario::InvalidNumber, Scenario::forRecipient('+31600000001'));
        self::assertSame(Scenario::ServerError, Scenario::forRecipient('+31600000004'));
    }

    public function testItNormalisesTheRecipientBeforeMatching(): void
    {
        self::assertSame(Scenario::InvalidNumber, Scenario::forRecipient('0031 600 000 001'));
        self::assertSame(Scenario::InvalidNumber, Scenario::forRecipient('+31-600-000-001'));
    }

    public function testAnOrdinaryNumberTriggersNothing(): void
    {
        self::assertNull(Scenario::forRecipient('+31612345678'));
    }
}
