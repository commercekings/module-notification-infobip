<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Test\Unit\Model\Webhook;

use CommerceKings\NotificationInfobip\Model\Config;
use CommerceKings\NotificationInfobip\Model\Webhook\WebhookAuth;
use PHPUnit\Framework\TestCase;

class WebhookAuthTest extends TestCase
{
    /** webhook secret per store view (one per website) */
    private array $secrets = [1 => 'secret-eu', 5 => 'secret-us', 9 => ''];

    private function auth(): WebhookAuth
    {
        $config = $this->createStub(Config::class);
        $config->method('getWebhookSecret')->willReturnCallback(fn ($storeId) => $this->secrets[$storeId] ?? '');
        $config->method('getWebhookBaseUrl')->willReturn('https://shop.test');
        $config->method('getAccountStores')->willReturn([1, 5, 9]);
        return new WebhookAuth($config);
    }

    public function testATokenPerAccountAndWebhookNeverTheSecret(): void
    {
        $auth = $this->auth();
        $inbound = $auth->token(1, WebhookAuth::INBOUND);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $inbound);
        $this->assertSame($inbound, $auth->token(1, WebhookAuth::INBOUND), 'stable');
        $this->assertNotSame($inbound, $auth->token(1, WebhookAuth::REPORT), 'one webhook\'s token does not open the other');
        $this->assertNotSame($inbound, $auth->token(5, WebhookAuth::INBOUND));
        $this->assertStringNotContainsString('secret-eu', $auth->url(1, WebhookAuth::REPORT, 'sms'));
        $this->assertSame('', $auth->token(9, WebhookAuth::INBOUND), 'no secret: no token');
        $this->assertSame('', $auth->url(9, WebhookAuth::INBOUND, 'sms'));
    }

    public function testTheUrlNamesTheChannelAndCarriesTheToken(): void
    {
        $auth = $this->auth();
        $this->assertSame('https://shop.test/ckinfobip/webhook/report?channel=viber&token=' . $auth->token(5, 'report'),
            $auth->url(5, WebhookAuth::REPORT, 'viber'));
    }

    public function testARequestIsTheAccountsWhoseTokenMatches(): void
    {
        $auth = $this->auth();
        $this->assertSame(5, $auth->authenticate($auth->token(5, 'inbound'), WebhookAuth::INBOUND));
        $this->assertSame(1, $auth->authenticate($auth->token(1, 'report'), WebhookAuth::REPORT));
        $this->assertNull($auth->authenticate($auth->token(1, 'report'), WebhookAuth::INBOUND), 'wrong webhook');
        $this->assertNull($auth->authenticate('0123456789abcdef0123456789abcdef01234567', WebhookAuth::INBOUND));
        $this->assertNull($auth->authenticate('', WebhookAuth::INBOUND));
    }
}
