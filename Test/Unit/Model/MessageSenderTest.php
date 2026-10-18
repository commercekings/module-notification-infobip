<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Test\Unit\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\StopListInterface;
use CommerceKings\NotificationInfobip\Exception\InfobipException;
use CommerceKings\NotificationInfobip\Model\Config;
use CommerceKings\NotificationInfobip\Model\Http\InfobipClient;
use CommerceKings\NotificationInfobip\Model\MessageSender;
use CommerceKings\NotificationInfobip\Model\TemplateMap;
use CommerceKings\NotificationInfobip\Model\Webhook\WebhookAuth;
use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;

class MessageSenderTest extends TestCase
{
    private array $calls = [];
    private array $stops = [];
    private ?\Throwable $failure = null;
    private array $templateRows = [];
    private string $locale = 'de_DE';
    private array $senders = ['sms' => 'Acme', 'whatsapp' => '+4915100000000', 'viber' => 'Acme'];

    private function sender(): MessageSender
    {
        $config = $this->createStub(Config::class);
        $config->method('getBaseUrl')->willReturn('https://abc.api.infobip.com');
        $config->method('getApiKey')->willReturn('key-1');
        $config->method('getSender')->willReturnCallback(fn ($storeId, $channel) => $this->senders[$channel] ?? '');
        $config->method('getTemplateMap')->willReturnCallback(fn () => $this->templateRows);
        $client = $this->createStub(InfobipClient::class);
        $client->method('send')->willReturnCallback(function (...$args) {
            $this->calls[] = $args;
            if ($this->failure) {
                throw $this->failure;
            }
            return [];
        });
        $auth = $this->createStub(WebhookAuth::class);
        $auth->method('url')->willReturnCallback(fn ($storeId, $webhook, $channel) =>
            "https://shop.test/ckinfobip/webhook/$webhook?channel=$channel&token=t");
        $stopList = $this->createStub(StopListInterface::class);
        $stopList->method('stop')->willReturnCallback(function (...$args) { $this->stops[] = $args; });
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn ($path) => $path === 'general/locale/code' ? $this->locale : null);
        return new MessageSender($config, $client, new TemplateMap($config), $auth, $stopList, $scope);
    }

    private function text(string $channel, string $type = 'cart_reminder_sms'): Delivery
    {
        return Delivery::text(1, $channel, '+381601234567', fn () => null, type: $type);
    }

    public function testAnSmsWithItsTextSenderAndDeliveryReport(): void
    {
        $this->sender()->send($this->text(Channel::SMS), new DeliveryContent([], text: 'Your cart is waiting'));
        [$base, $key, $path, $body] = $this->calls[0];
        $this->assertSame(['https://abc.api.infobip.com', 'key-1', '/sms/2/text/advanced'], [$base, $key, $path]);
        $this->assertSame(['messages' => [[
            'destinations' => [['to' => '381601234567']],
            'from' => 'Acme',
            'text' => 'Your cart is waiting',
            'notifyUrl' => 'https://shop.test/ckinfobip/webhook/report?channel=sms&token=t',
            'notifyContentType' => 'application/json',
        ]]], $body, 'international without "+", and never a failover');
    }

    public function testAViberMessage(): void
    {
        $this->sender()->send($this->text(Channel::VIBER), new DeliveryContent([], text: 'Hi'));
        [, , $path, $body] = $this->calls[0];
        $this->assertSame('/viber/2/messages', $path);
        $this->assertSame([
            'sender' => 'Acme',
            'destinations' => [['to' => '381601234567']],
            'content' => ['text' => 'Hi', 'type' => 'TEXT'],
            'webhooks' => ['delivery' => ['url' => 'https://shop.test/ckinfobip/webhook/report?channel=viber&token=t']],
        ], $body['messages'][0]);
    }

    /** WhatsApp marketing takes approved templates only: the type's for the locale, its variables in order. */
    public function testAWhatsappTemplateByTypeAndLocale(): void
    {
        $this->templateRows = [
            ['type' => 'cart_reminder_whatsapp', 'locale' => '', 'template' => 'cart_any', 'language' => 'en', 'variables' => 'name'],
            ['type' => 'cart_reminder_whatsapp', 'locale' => 'de', 'template' => 'cart_de', 'language' => 'de',
                'variables' => 'name, product,link'],
        ];
        $this->sender()->send($this->text(Channel::WHATSAPP, 'cart_reminder_whatsapp'),
            new DeliveryContent(['name' => 'Jane', 'product' => 'Notebook', 'link' => 'https://shop.test/cart', 'other' => 'x']));
        [, , $path, $body] = $this->calls[0];
        $this->assertSame('/whatsapp/1/message/template', $path);
        $this->assertSame([
            'from' => '4915100000000',
            'to' => '381601234567',
            'content' => ['templateName' => 'cart_de', 'templateData' => ['body' => ['placeholders' =>
                ['Jane', 'Notebook', 'https://shop.test/cart']]], 'language' => 'de'],
            'notifyUrl' => 'https://shop.test/ckinfobip/webhook/report?channel=whatsapp&token=t',
        ], $body['messages'][0]);
    }

    public function testWithoutATemplateWhatsappIsNotSent(): void
    {
        try {
            $this->sender()->send($this->text(Channel::WHATSAPP, 'cart_reminder_whatsapp'), new DeliveryContent([]));
            $this->fail('Expected no template');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('No WhatsApp template for "cart_reminder_whatsapp" (de_DE)', $error->getMessage());
        }
        $this->assertSame([], $this->calls);
        // Delivery::$templateId is the fallback, in the locale's language
        $this->sender()->send(new Delivery(1, 'cart_tpl', '', '+381601234567', fn () => null, channel: Channel::WHATSAPP),
            new DeliveryContent([]));
        $this->assertSame(['templateName' => 'cart_tpl', 'templateData' => ['body' => ['placeholders' => []]],
            'language' => 'de'], $this->calls[0][3]['messages'][0]['content']);
    }

    /** Refused because the person blocked or opted out of the sender: their opt-out, then the send fails. */
    public function testAnOptOutRefusalStopsTheNumber(): void
    {
        $this->failure = new InfobipException('rejected', 'REJECTED_DESTINATION_BLOCKED', 200);
        try {
            $this->sender()->send($this->text(Channel::SMS), new DeliveryContent([], text: 'Hi'));
            $this->fail('Expected the refusal');
        } catch (InfobipException) {
        }
        $this->assertSame([[Channel::SMS, '+381601234567', StopListInterface::REASON_REPLY]], $this->stops);
    }

    public function testAnotherRefusalStopsNothing(): void
    {
        $this->failure = new InfobipException('rejected', 'REJECTED_NOT_ENOUGH_CREDITS', 200);
        try {
            $this->sender()->send($this->text(Channel::SMS), new DeliveryContent([], text: 'Hi'));
            $this->fail('Expected the refusal');
        } catch (InfobipException) {
        }
        $this->assertSame([], $this->stops);
    }

    public function testNothingIsSentWithoutASenderATextOrOnAnotherChannel(): void
    {
        $this->senders['sms'] = '';
        foreach ([
            fn () => $this->sender()->send($this->text(Channel::SMS), new DeliveryContent([], text: 'Hi')),
            fn () => $this->sender()->send($this->text(Channel::VIBER), new DeliveryContent([], text: '  ')),
            fn () => $this->sender()->send($this->text('telegram'), new DeliveryContent([], text: 'Hi')),
        ] as $send) {
            try {
                $send();
                $this->fail('Expected nothing to be sent');
            } catch (\RuntimeException | \InvalidArgumentException) {
            }
        }
        $this->assertSame([], $this->calls);
    }

    public function testOptOutNames(): void
    {
        foreach (['REJECTED_DESTINATION_BLOCKED', 'EC_OPT_OUT', 'USER_UNSUBSCRIBED', 'blocked'] as $name) {
            $this->assertTrue(MessageSender::isOptOut($name), $name);
        }
        foreach (['', 'REJECTED_NOT_ENOUGH_CREDITS', 'EC_ABSENT_SUBSCRIBER', 'DELIVERED_TO_HANDSET'] as $name) {
            $this->assertFalse(MessageSender::isOptOut($name), $name);
        }
    }
}
