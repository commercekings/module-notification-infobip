<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Test\Integration;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\QuietHoursInterface;
use CommerceKings\Notification\Api\SenderInterface;
use CommerceKings\Notification\Api\StopListInterface;
use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\Notification\Model\Config as NotificationConfig;
use CommerceKings\Notification\Model\Cooldown;
use CommerceKings\Notification\Model\ProviderPool;
use CommerceKings\Notification\Model\RecipientNormalizerPool;
use CommerceKings\Notification\Model\Sender;
use CommerceKings\NotificationInfobip\Model\Config;
use CommerceKings\NotificationInfobip\Model\Http\InfobipClient;
use CommerceKings\NotificationInfobip\Model\MessageSender;
use CommerceKings\NotificationInfobip\Model\Provider\InfobipProvider;
use CommerceKings\NotificationInfobip\Model\TemplateMap;
use CommerceKings\NotificationInfobip\Model\Webhook\InboundProcessor;
use CommerceKings\NotificationInfobip\Model\Webhook\ReportProcessor;
use CommerceKings\NotificationInfobip\Model\Webhook\WebhookAuth;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\App\Emulation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Infobip through Notification's real sender, stop list and normalizer on a real database, with a stub HTTP client (no
 * call reaches Infobip): an SMS goes out in Infobip's form, a STOP reply stops the number so the next marketing message
 * is dropped, START lifts it, a permanent failure suppresses it, and a timeout counts as sent.
 */
class InfobipTest extends TestCase
{
    private const NUMBER = '+381601234560';

    private \Magento\Framework\ObjectManagerInterface $om;
    private array $history = [];

    protected function setUp(): void
    {
        $root = getenv('MAGENTO_ROOT') ?: (defined('BP') ? BP : getcwd());
        if (!is_file($root . '/app/bootstrap.php')) {
            throw new \RuntimeException('Run from the Magento root or set MAGENTO_ROOT.');
        }
        require_once $root . '/app/bootstrap.php';
        $this->om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
    }

    protected function tearDown(): void
    {
        foreach ([Channel::SMS, Channel::VIBER] as $channel) {
            $this->om->get(StopListInterface::class)->resume($channel, self::NUMBER);
        }
    }

    private function sender(array $responses): Sender
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $config = $this->createStub(Config::class);
        $config->method('getBaseUrl')->willReturn('https://abc.api.infobip.com');
        $config->method('getApiKey')->willReturn('key-1');
        $config->method('getSender')->willReturn('Acme');
        $config->method('getWebhookSecret')->willReturn('secret');
        $config->method('getWebhookBaseUrl')->willReturn('https://shop.test');
        $messages = new MessageSender($config, new InfobipClient(new Client(['handler' => $stack])), new TemplateMap($config),
            new WebhookAuth($config), $this->om->get(StopListInterface::class), $this->om->get(ScopeConfigInterface::class));
        $notificationConfig = $this->createStub(NotificationConfig::class);
        $notificationConfig->method('getCooldownDays')->willReturn(0);
        return new Sender(new ProviderPool($notificationConfig, [
            new InfobipProvider($messages, 'infobip_sms', 'Infobip SMS', Channel::SMS),
            new InfobipProvider($messages, 'infobip_viber', 'Infobip Viber', Channel::VIBER),
        ]), $this->createStub(Emulation::class), $this->om->create(Cooldown::class, ['config' => $notificationConfig]),
            $notificationConfig, new NullLogger(), $this->om->get(RecipientNormalizerPool::class),
            $this->om->get(StopListInterface::class), $this->createStub(QuietHoursInterface::class));
    }

    private function text(string $channel = Channel::SMS): Delivery
    {
        return Delivery::text(1, $channel, '+381 60 123 4560', fn () => new DeliveryContent([], text: 'Your cart is waiting'),
            false, null, true, 'cart_reminder_sms');
    }

    private static function accepted(): Response
    {
        return new Response(200, [], '{"messages":[{"messageId":"m1","status":{"groupName":"PENDING"}}]}');
    }

    public function testAnSmsGoesOutInInfobipsForm(): void
    {
        $this->assertSame(SenderInterface::SENT, $this->sender([self::accepted()])->send($this->text()));
        $body = json_decode((string)$this->history[0]['request']->getBody(), true);
        $this->assertSame([['to' => '381601234560']], $body['messages'][0]['destinations'], 'normalized, without "+"');
        $this->assertSame('Your cart is waiting', $body['messages'][0]['text']);
        $this->assertStringStartsWith('https://shop.test/ckinfobip/webhook/report?channel=sms&token=',
            $body['messages'][0]['notifyUrl']);
        $this->assertArrayNotHasKey('failover', $body['messages'][0]);
    }

    public function testAStopReplyStopsTheNumberUntilStart(): void
    {
        $inbound = $this->om->create(InboundProcessor::class);
        $this->assertSame(['stop'], $inbound->process(['results' => [['from' => '381601234560', 'text' => 'STOP']]], 'sms'));
        $stopList = $this->om->get(StopListInterface::class);
        $this->assertSame(StopListInterface::REASON_REPLY, $stopList->getReason(Channel::SMS, self::NUMBER));
        $this->assertSame(SenderInterface::STOPPED, $this->sender([self::accepted()])->send($this->text()),
            'a marketing message to the stopped number is dropped');
        $this->assertSame([], $this->history, 'nothing reached Infobip');
        $this->assertSame(['start'], $inbound->process(['results' => [['from' => '381601234560', 'text' => 'START']]], 'sms'));
        $this->assertFalse($stopList->isStopped(Channel::SMS, self::NUMBER));
    }

    public function testAPermanentFailureSuppressesTheNumberOnItsChannel(): void
    {
        $this->om->create(ReportProcessor::class)->process(['results' => [['to' => '381601234560',
            'status' => ['groupName' => 'UNDELIVERABLE'], 'error' => ['name' => 'EC_ABSENT_SUBSCRIBER', 'permanent' => true]]]],
            'viber');
        $stopList = $this->om->get(StopListInterface::class);
        $this->assertSame(StopListInterface::REASON_UNDELIVERABLE, $stopList->getReason(Channel::VIBER, self::NUMBER));
        $this->assertFalse($stopList->isStopped(Channel::SMS, self::NUMBER), 'only on that channel');
    }

    public function testATimeoutAfterTheRequestCountsAsSent(): void
    {
        $this->expectException(MessageSentException::class);
        $this->sender([new RequestException('timed out', new Request('POST', 'https://abc.api.infobip.com'))])
            ->send($this->text(Channel::VIBER));
    }
}
