<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Test\Unit\Controller;

use CommerceKings\NotificationInfobip\Controller\Webhook\Inbound;
use CommerceKings\NotificationInfobip\Controller\Webhook\Report;
use CommerceKings\NotificationInfobip\Model\Webhook\InboundProcessor;
use CommerceKings\NotificationInfobip\Model\Webhook\ReportProcessor;
use CommerceKings\NotificationInfobip\Model\Webhook\WebhookAuth;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use PHPUnit\Framework\TestCase;

class WebhookTest extends TestCase
{
    private ?int $account = 1;
    private array $processed = [];
    private array $auth = [];
    private ?int $status = null;

    private function request(array $params, string $body): Http
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(fn ($name, $default = null) => $params[$name] ?? $default);
        $request->method('getContent')->willReturn($body);
        return $request;
    }

    private function raws(): RawFactory
    {
        $raw = $this->createStub(Raw::class);
        $raw->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($raw) {
            $this->status = $code;
            return $raw;
        });
        $raw->method('setHeader')->willReturnSelf();
        $raw->method('setContents')->willReturnSelf();
        $factory = $this->createStub(RawFactory::class);
        $factory->method('create')->willReturn($raw);
        return $factory;
    }

    private function auth(): WebhookAuth
    {
        $auth = $this->createStub(WebhookAuth::class);
        $auth->method('authenticate')->willReturnCallback(function ($token, $webhook) {
            $this->auth[] = [$token, $webhook];
            return $this->account;
        });
        return $auth;
    }

    public function testAnAuthorizedReportIsProcessedAndAnswered200(): void
    {
        $processor = $this->createStub(ReportProcessor::class);
        $processor->method('process')->willReturnCallback(function ($payload, $channel) {
            $this->processed[] = [$payload, $channel];
            return [];
        });
        (new Report($this->request(['token' => 't1', 'channel' => 'sms'], '{"results":[{"to":"381601234567"}]}'),
            $this->raws(), $this->auth(), $processor))->execute();
        $this->assertSame(200, $this->status);
        $this->assertSame([['t1', WebhookAuth::REPORT]], $this->auth);
        $this->assertSame([[['results' => [['to' => '381601234567']]], 'sms']], $this->processed);
    }

    public function testWithoutAMatchingTokenNothingIsDone(): void
    {
        $this->account = null;
        $processor = $this->createMock(InboundProcessor::class);
        $processor->expects($this->never())->method('process');
        (new Inbound($this->request(['channel' => 'sms'], '{"results":[{"from":"381601234567","text":"STOP"}]}'),
            $this->raws(), $this->auth(), $processor))->execute();
        $this->assertSame(403, $this->status);
        $this->assertSame([['', WebhookAuth::INBOUND]], $this->auth);
    }

    public function testABodyThatIsNotJsonIsAnEmptyPayload(): void
    {
        $processor = $this->createStub(InboundProcessor::class);
        $processor->method('process')->willReturnCallback(function ($payload, $channel) {
            $this->processed[] = [$payload, $channel];
            return [];
        });
        (new Inbound($this->request(['token' => 't'], 'not json'), $this->raws(), $this->auth(), $processor))->execute();
        $this->assertSame([[[], null]], $this->processed);
        $this->assertSame(200, $this->status);
    }
}
