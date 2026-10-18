<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Test\Unit\Model\Webhook;

use CommerceKings\Notification\Api\StopListInterface;
use CommerceKings\NotificationInfobip\Model\Webhook\InboundProcessor;
use CommerceKings\NotificationInfobip\Model\Webhook\ReportProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProcessorsTest extends TestCase
{
    private array $calls = [];
    private array $warnings = [];
    private array $infos = [];

    private function stopList(): StopListInterface
    {
        $stopList = $this->createStub(StopListInterface::class);
        $record = function ($method) {
            return function (...$args) use ($method) {
                if (($args[1] ?? '') === '+12') {
                    throw new \InvalidArgumentException('not a number');
                }
                $this->calls[] = [$method, ...$args];
            };
        };
        $stopList->method('stop')->willReturnCallback($record('stop'));
        $stopList->method('resume')->willReturnCallback($record('resume'));
        return $stopList;
    }

    private function logger(): LoggerInterface
    {
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) { $this->warnings[] = $message; });
        $logger->method('info')->willReturnCallback(function ($message) { $this->infos[] = $message; });
        return $logger;
    }

    private function inbound(array $results, ?string $channel): array
    {
        return (new InboundProcessor($this->stopList(), $this->logger()))->process(['results' => $results], $channel);
    }

    public function testStopAndStartWordsWithTheMissingPlusAdded(): void
    {
        $this->assertSame(['stop', 'start', ''], $this->inbound([
            ['from' => '381601234567', 'cleanText' => 'Stop!'],
            ['from' => '381601234567', 'text' => 'start'],
            ['from' => '381601234567', 'text' => 'Stop sending me this weird thing'],
        ], 'sms'));
        $this->assertSame([['stop', 'sms', '+381601234567', StopListInterface::REASON_REPLY],
            ['resume', 'sms', '+381601234567']], $this->calls);
    }

    public function testOnlyAWholeReplyThatIsAKeywordCountsIgnoringCaseSpacesAndPunctuation(): void
    {
        foreach (['opt out', 'Opt-Out', 'OPT OUT!', 'optout', 'stop all', 'STOP ALL', 'STOP!!', 'stop.', ' Stop ',
            'Stop 🛑', '🛑 stop', 'Revoke', 'stopall', 'Un-subscribe', 'cancel', 'QUIT!', 'e.n.d'] as $text) {
            $this->assertSame('stop', $this->inbound([['from' => '381601234567', 'text' => $text]], 'sms')[0], $text);
        }
        foreach (['stop please', 'Stop texting me', 'Cancel my order', 'Unsubscribe me', 'opt out now', 'stopwatch',
            "Don't stop", 'please stop the music', 'nonstop', 'opt', 'stopped', 'HELP', 'Yes stop', 'yes please',
            'start now', 'Started', 'unstoppable', '🙂', ''] as $text) {
            $this->assertSame('', $this->inbound([['from' => '381601234567', 'text' => $text]], 'sms')[0], $text);
        }
        foreach (['START', 'start!', ' Unstop ', 'yes', 'Yes!', 'YES 👍', 'un-stop'] as $text) {
            $this->assertSame('start', $this->inbound([['from' => '381601234567', 'text' => $text]], 'sms')[0], $text);
        }
    }

    public function testAnUnrecognizedReplyIsLoggedWithoutTheNumberOrText(): void
    {
        $this->assertSame([''], $this->inbound([['from' => '381601234567', 'text' => 'Where is my parcel?']], 'sms'));
        $this->assertSame(['CommerceKings_NotificationInfobip: unrecognized reply.'], $this->infos);
    }

    public function testWhatsappStopPromotionsButtonAndViberSubscriptionEvents(): void
    {
        $this->assertSame(['stop', 'stop'], $this->inbound([
            ['from' => '381601234567', 'message' => ['type' => 'BUTTON', 'payload' => 'Stop promotions']],
            ['from' => '381601234567', 'message' => ['type' => 'TEXT', 'text' => 'stop promotions']],
        ], 'whatsapp'));
        $this->assertSame(['stop', 'start'], $this->inbound([
            ['from' => '381601234567', 'message' => ['type' => 'UNSUBSCRIBED']],
            ['from' => '381601234567', 'event' => 'SUBSCRIBED'],
        ], 'viber'));
    }

    public function testTheChannelComesFromTheUrlElseFromInfobip(): void
    {
        $this->inbound([['from' => '381601234567', 'integrationType' => 'WHATSAPP', 'message' => ['type' => 'BUTTON',
            'payload' => 'Stop promotions']]], null);
        $this->inbound([['from' => '381601234567', 'message' => ['type' => 'UNSUBSCRIBED']]], 'viber');
        $this->inbound([['from' => '381601234567', 'event' => 'SUBSCRIBED']], 'viber');
        $this->assertSame([['stop', 'whatsapp', '+381601234567', StopListInterface::REASON_REPLY],
            ['stop', 'viber', '+381601234567', StopListInterface::REASON_REPLY], ['resume', 'viber', '+381601234567']],
            $this->calls);
        $this->calls = [];
        $this->assertSame([''], $this->inbound([['from' => '381601234567', 'text' => 'STOP']], null), 'no channel: left alone');
        $this->assertSame([''], $this->inbound([['from' => '381601234567', 'text' => 'STOP']], 'telegram'));
        $this->assertSame([], $this->calls);
    }

    public function testANumberNotificationCannotTakeIsLoggedWithoutTheNumber(): void
    {
        $this->assertSame([''], $this->inbound([['from' => '12', 'text' => 'STOP']], 'sms'));
        $this->assertCount(1, $this->warnings);
        $this->assertStringNotContainsString('12', str_replace('sms', '', $this->warnings[0]));
    }

    private function report(array $result, string $channel = 'sms'): ?string
    {
        return (new ReportProcessor($this->stopList(), $this->logger()))->process(['results' => [$result]], $channel)[0];
    }

    public function testDeliveryReports(): void
    {
        $this->assertNull($this->report(['to' => '381601234567', 'status' => ['groupName' => 'DELIVERED']]));
        $this->assertNull($this->report(['to' => '381601234567', 'status' => ['groupName' => 'PENDING']]));
        $this->assertNull($this->report(['to' => '381601234567', 'status' => ['groupName' => 'EXPIRED']]));
        $this->assertNull($this->report(['to' => '381601234567', 'status' => ['groupName' => 'UNDELIVERABLE'],
            'error' => ['name' => 'EC_UNKNOWN_ERROR', 'permanent' => false]]), 'a temporary failure');
        $this->assertSame(StopListInterface::REASON_UNDELIVERABLE, $this->report(['to' => '381601234567',
            'status' => ['groupName' => 'UNDELIVERABLE', 'name' => 'UNDELIVERABLE_NOT_DELIVERED'],
            'error' => ['name' => 'EC_ABSENT_SUBSCRIBER', 'permanent' => true]], 'viber'));
        $this->assertSame(StopListInterface::REASON_REPLY, $this->report(['to' => '381601234567',
            'status' => ['groupName' => 'REJECTED', 'name' => 'REJECTED_DESTINATION_BLOCKED']], 'whatsapp'));
        $this->assertSame([['stop', 'viber', '+381601234567', StopListInterface::REASON_UNDELIVERABLE],
            ['stop', 'whatsapp', '+381601234567', StopListInterface::REASON_REPLY]], $this->calls);
        $this->assertNull($this->report(['to' => '12', 'status' => ['groupName' => 'UNDELIVERABLE'],
            'error' => ['permanent' => true]]));
        $this->assertCount(1, $this->warnings);
    }
}
