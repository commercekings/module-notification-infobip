<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Test\Unit\Model;

use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\NotificationInfobip\Model\MessageSender;
use CommerceKings\NotificationInfobip\Model\Provider\InfobipProvider;
use PHPUnit\Framework\TestCase;

class ProviderTest extends TestCase
{
    public function testAProviderOnItsChannelHandsTheMessageToTheSender(): void
    {
        $sent = [];
        $sender = $this->createStub(MessageSender::class);
        $sender->method('send')->willReturnCallback(function (...$args) use (&$sent) { $sent[] = $args; });
        $provider = new InfobipProvider($sender, 'infobip_viber', 'Infobip Viber', 'viber');
        $this->assertSame(['infobip_viber', 'Infobip Viber', 'viber', false], [$provider->getCode(), $provider->getLabel(),
            $provider->getChannel(), $provider->supportsOneClickUnsubscribe()]);
        $delivery = Delivery::text(1, 'viber', '+381601234567', fn () => null);
        $content = new DeliveryContent([], text: 'Hi');
        $provider->send($delivery, $content);
        $this->assertSame([[$delivery, $content]], $sent);
    }
}
