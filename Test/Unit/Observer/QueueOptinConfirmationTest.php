<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Test\Unit\Observer;

use CommerceKings\Notification\Api\ProviderInterface;
use CommerceKings\Notification\Api\ProviderPoolInterface;
use CommerceKings\Notification\Api\QueueInterface;
use CommerceKings\NotificationInfobip\Model\Config;
use CommerceKings\NotificationInfobip\Model\Optin\Pending;
use CommerceKings\NotificationInfobip\Observer\QueueOptinConfirmation;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QueueOptinConfirmationTest extends TestCase
{
    private array $published = [];
    private bool $enabled = true;
    private ?string $provider = 'infobip_sms';
    private ?\Throwable $brokerDown = null;
    private array $pending = [];
    private array $warnings = [];

    private function observe(array $data): void
    {
        $config = $this->createStub(Config::class);
        $config->method('isOptinEnabled')->willReturnCallback(fn () => $this->enabled);
        $providers = $this->createStub(ProviderPoolInterface::class);
        $providers->method('getSelected')->willReturnCallback(function () {
            if ($this->provider === null) {
                throw new LocalizedException(__('none'));
            }
            $provider = $this->createStub(ProviderInterface::class);
            $provider->method('getCode')->willReturn($this->provider);
            return $provider;
        });
        $queue = $this->createStub(QueueInterface::class);
        $queue->method('publish')->willReturnCallback(function (...$args) {
            if ($this->brokerDown) {
                throw $this->brokerDown;
            }
            $this->published[] = $args;
        });
        $pending = $this->createStub(Pending::class);
        foreach (['queued', 'notQueued'] as $method) {
            $pending->method($method)->willReturnCallback(function (int $id) use ($method) { $this->pending[] = [$method, $id]; });
        }
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) { $this->warnings[] = $message; });
        (new QueueOptinConfirmation($config, $providers, $queue, $pending, $logger))->execute(new Observer($data + ['subscription_id' => 9,
            'channel' => 'sms', 'store_id' => 1, 'contact_id' => 7, 'first_for_address' => true]));
    }

    public function testTheFirstSignUpOfANumberIsConfirmedWhenInfobipSendsIt(): void
    {
        $this->observe([]);
        $this->observe(['channel' => 'viber']);
        $this->assertSame([['infobip_optin', '9'], ['infobip_optin', '9']], $this->published);
        $this->assertSame([['queued', 9], ['queued', 9]], $this->pending, 'recorded as pending before it is published');
    }

    public function testABrokerThatIsDownDoesNotLoseItOrFailTheSignUp(): void
    {
        $this->brokerDown = new \RuntimeException('AMQP connection refused');
        $this->observe([]);
        $this->assertSame([['queued', 9], ['notQueued', 9]], $this->pending, 'the retry cron publishes it again');
        $this->assertStringContainsString('subscription 9 could not be queued (RuntimeException)', $this->warnings[0]);
    }

    public function testNothingElseIsConfirmed(): void
    {
        $this->observe(['first_for_address' => false]);
        $this->observe(['channel' => 'email']);
        $this->observe(['contact_id' => null]);
        $this->enabled = false;
        $this->observe([]);
        $this->enabled = true;
        $this->provider = 'twilio_sms';
        $this->observe([]);
        $this->provider = null;
        $this->observe([]);
        $this->assertSame([], $this->published, 'another provider\'s module confirms its own numbers');
    }
}
