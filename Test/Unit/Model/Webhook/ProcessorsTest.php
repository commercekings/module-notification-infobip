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

    public function testAKeywordForwardingSetupDoesNotLoseTheReply(): void
    {
        $this->assertSame(['stop', 'stop', 'start', 'stop', ''], $this->inbound([
            // forwarding for the keyword STOP: the whole reply is the keyword, nothing after it
            ['from' => '381601234567', 'text' => 'STOP', 'keyword' => 'STOP', 'cleanText' => ''],
            // forwarding for the keyword SHOP: the reply after it is STOP
            ['from' => '381601234567', 'text' => 'SHOP STOP', 'keyword' => 'SHOP', 'cleanText' => 'STOP'],
            ['from' => '381601234567', 'text' => 'shop start!', 'keyword' => 'SHOP', 'cleanText' => 'start!'],
            ['from' => '381601234567', 'keyword' => 'STOP', 'cleanText' => ''],
            ['from' => '381601234567', 'text' => 'STOP please', 'keyword' => 'STOP', 'cleanText' => 'please'],
        ], 'sms'));
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
        $this->assertNull($this->report(['to' => '381601234567', 'status' => ['groupName' => 'UNDELIVERABLE',
            'name' => 'UNDELIVERABLE_NOT_DELIVERED'], 'error' => ['name' => 'EC_ABSENT_SUBSCRIBER', 'permanent' => false]],
            'viber'), 'switched off or out of coverage: temporary');
        $this->assertSame(StopListInterface::REASON_UNDELIVERABLE, $this->report(['to' => '381601234567',
            'status' => ['groupName' => 'UNDELIVERABLE', 'name' => 'UNDELIVERABLE_NOT_DELIVERED'],
            'error' => ['name' => 'EC_UNKNOWN_SUBSCRIBER', 'permanent' => true]], 'viber'));
        $this->assertSame(StopListInterface::REASON_REPLY, $this->report(['to' => '381601234567',
            'status' => ['groupName' => 'REJECTED', 'name' => 'REJECTED_NETWORK'],
            'error' => ['name' => 'EC_MARKETING_OPT_OUT', 'permanent' => true]], 'whatsapp'));
        $this->assertSame([['stop', 'viber', '+381601234567', StopListInterface::REASON_UNDELIVERABLE],
            ['stop', 'whatsapp', '+381601234567', StopListInterface::REASON_REPLY]], $this->calls);
        $this->assertNull($this->report(['to' => '12', 'status' => ['groupName' => 'UNDELIVERABLE'],
            'error' => ['name' => 'EC_UNKNOWN_SUBSCRIBER', 'permanent' => true]]));
        $this->assertCount(1, $this->warnings);
    }

    /** The trial's real report: the shop's demo whitelist, not the shopper's number. */
    public function testTheDemoWhitelistErrorStopsNobodyAndIsLoggedWithoutTheNumber(): void
    {
        $this->assertNull($this->report(['to' => '381611234567', 'status' => ['groupId' => 5, 'groupName' => 'REJECTED',
            'id' => 18, 'name' => 'REJECTED_DESTINATION_NOT_REGISTERED'], 'error' => ['groupId' => 3,
            'groupName' => 'OPERATOR_ERRORS', 'id' => 579, 'name' => 'EC_DEST_ADDRESS_NOT_IN_SMS_DEMO', 'permanent' => true]]));
        $this->assertSame([], $this->calls);
        $this->assertSame(['CommerceKings_NotificationInfobip: a message failed (REJECTED_DESTINATION_NOT_REGISTERED /'
            . ' EC_DEST_ADDRESS_NOT_IN_SMS_DEMO); no number was suppressed.'], $this->infos);
    }

    public function testAnUnknownSubscriberIsSuppressedAndAnExplicitOptOutIsAReply(): void
    {
        $this->assertSame(StopListInterface::REASON_UNDELIVERABLE, $this->report(['to' => '381611234567',
            'status' => ['groupName' => 'UNDELIVERABLE', 'name' => 'UNDELIVERABLE_NOT_DELIVERED'],
            'error' => ['name' => 'EC_UNKNOWN_SUBSCRIBER', 'permanent' => true]]));
        $this->assertSame(StopListInterface::REASON_REPLY, $this->report(['to' => '381611234567',
            'status' => ['groupName' => 'UNDELIVERABLE', 'name' => 'UNDELIVERABLE_NOT_DELIVERED'],
            'error' => ['name' => 'EC_CONTENT_BLOCKED', 'permanent' => true]]));
        $this->assertSame(StopListInterface::REASON_REPLY, $this->report(['to' => '381611234567',
            'status' => ['groupName' => 'UNDELIVERABLE'], 'error' => ['name' => 'EC_BLOCKED_USER', 'permanent' => true]], 'viber'));
        $this->assertSame([['stop', 'sms', '+381611234567', StopListInterface::REASON_UNDELIVERABLE],
            ['stop', 'sms', '+381611234567', StopListInterface::REASON_REPLY],
            ['stop', 'viber', '+381611234567', StopListInterface::REASON_REPLY]], $this->calls);
    }

    public function testAccountSenderAndNetworkErrorsStopNobodyEvenWhenPermanent(): void
    {
        foreach ([
            ['REJECTED_NOT_ENOUGH_CREDITS', 'EC_MONTHLY_LIMIT_REACHED'], ['REJECTED_SOURCE', 'EC_INVALID_SOURCE_ADDRESS'],
            ['REJECTED_SENDER', 'EC_BLACKLISTED_SENDERADDRESS'], ['REJECTED_ROUTE_NOT_AVAILABLE', 'EC_ACCOUNT_ACCESS_DENIED'],
            ['REJECTED_DESTINATION_BLOCKLISTED', 'EC_BLACKLISTED_DESTINATIONADDRESS'], ['REJECTED_NETWORK', 'EC_SENDER_NOT_REGISTERED'],
            ['REJECTED_NETWORK', 'EC_INVALID_TEMPLATE'], ['UNDELIVERABLE_REJECTED_OPERATOR', 'EC_MOBILE_OPERATOR_NETWORK_ERROR'],
            ['REJECTED_SOMETHING_NEW', 'EC_SOMETHING_NEW'],
        ] as [$status, $error]) {
            $this->assertNull($this->report(['to' => '381611234567', 'status' => ['groupName' => 'REJECTED', 'name' => $status],
                'error' => ['name' => $error, 'permanent' => true]]), "$status / $error");
        }
        $this->assertSame([], $this->calls);
        $this->assertCount(9, $this->infos);
        foreach ($this->infos as $line) {
            $this->assertStringNotContainsString('381611234567', $line);
        }
    }
}
