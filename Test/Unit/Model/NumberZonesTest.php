<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Test\Unit\Model;

use CommerceKings\NotificationInfobip\Model\NumberZones;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\TestCase;

class NumberZonesTest extends TestCase
{
    private function zones(): NumberZones
    {
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn('Europe/Berlin');
        return new NumberZones($timezone);
    }

    private function names(?string $recipient): array
    {
        return array_map(fn (\DateTimeZone $zone) => $zone->getName(), $this->zones()->getZones(1, 'sms', $recipient));
    }

    public function testTheZonesOfTheNumbersArea(): void
    {
        $this->assertSame(['America/New_York'], $this->names('+12125550100'));
        $this->assertSame(['Europe/Belgrade'], $this->names('+381601234567'));
    }

    /** A number it cannot place keeps the store's zone: quiet hours still apply (never "never quiet"). */
    public function testANumberItCannotPlaceKeepsTheStoresZone(): void
    {
        $this->assertSame(['Europe/Berlin'], $this->names('not a number'));
    }

    public function testTheWholeStoreGetsNoZone(): void
    {
        $this->assertSame([], $this->names(null));
        $this->assertSame([], $this->names(''));
    }
}
