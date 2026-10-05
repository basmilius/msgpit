<?php

declare(strict_types=1);

namespace Msgpit\Tests\Unit;

use Msgpit\Core\Scenario;
use PHPUnit\Framework\TestCase;

final class ScenarioCatalogueTest extends TestCase
{
    public function testEveryScenarioHasAMagicNumberAndADescription(): void
    {
        foreach (Scenario::cases() as $scenario) {
            self::assertNotNull($scenario->recipient(), "{$scenario->value} has no magic number");
            self::assertNotSame('', $scenario->description());
        }
    }

    public function testAMagicNumberRoundTrips(): void
    {
        foreach (Scenario::cases() as $scenario) {
            self::assertSame($scenario, Scenario::forRecipient((string) $scenario->recipient()));
        }
    }

    public function testTheMagicNumbersAreUnique(): void
    {
        $numbers = array_keys(Scenario::magicNumbers());

        self::assertSame($numbers, array_unique($numbers));
        self::assertCount(count(Scenario::cases()), $numbers);
    }
}
