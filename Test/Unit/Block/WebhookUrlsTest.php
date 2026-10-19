<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Test\Unit\Block;

use CommerceKings\NotificationInfobip\Block\Adminhtml\System\Config\WebhookUrls;
use CommerceKings\NotificationInfobip\Model\Webhook\WebhookAuth;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Escaper;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/** The inbound URLs in the admin: visible (no <dd>, which the config accordion hides) and copyable. */
class WebhookUrlsTest extends TestCase
{
    private function html(?string $token): string
    {
        $auth = $this->createStub(WebhookAuth::class);
        $auth->method('url')->willReturnCallback(fn ($storeId, $webhook, $channel) => $token === null ? ''
            : "https://shop.test/ckinfobip/webhook/$webhook?channel=$channel&token=$token");
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn ($name) => $name === 'store' ? '3' : null);
        $element = $this->createStub(AbstractElement::class);
        $element->method('getHtmlId')->willReturn('commercekings_notification_infobip_webhook_urls');
        // built without Magento's block constructor (it needs the object manager): the parts the rendering uses
        $block = (new \ReflectionClass(WebhookUrls::class))->newInstanceWithoutConstructor();
        foreach (['auth' => $auth, 'stores' => $this->createStub(StoreManagerInterface::class),
            '_request' => $request, '_escaper' => new Escaper()] as $property => $value) {
            $reflection = new \ReflectionProperty($block, $property);
            $reflection->setValue($block, $value);
        }
        return (new \ReflectionMethod($block, '_getElementHtml'))->invoke($block, $element);
    }

    public function testOneCopyableFieldPerChannelAndNoDd(): void
    {
        $html = $this->html('ab"c');
        $this->assertStringNotContainsString('<dd', $html);
        $this->assertStringNotContainsString('<dl', $html);
        foreach (['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'viber' => 'Viber'] as $channel => $label) {
            $id = 'commercekings_notification_infobip_webhook_urls_' . $channel;
            $this->assertStringContainsString('<label for="' . $id . '">' . $label . '</label>', $html);
            $this->assertStringContainsString('id="' . $id . '" value="https://shop.test/ckinfobip/webhook/inbound?channel='
                . $channel . '&amp;token=ab&quot;c"', $html, 'escaped, in a readonly input');
        }
        $this->assertSame(3, substr_count($html, 'readonly="readonly"'));
    }

    public function testWithoutASecretItAsksForOne(): void
    {
        $html = $this->html(null);
        $this->assertStringContainsString('Set a webhook secret and save first.', $html);
        $this->assertStringNotContainsString('<input', $html);
    }
}
