<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Test\Unit\Observer;

use CommerceKings\Notification\Api\ProviderInterface;
use CommerceKings\Notification\Api\ProviderPoolInterface;
use CommerceKings\Notification\Api\QueueInterface;
use CommerceKings\NotificationInfobip\Model\Config;
use CommerceKings\NotificationInfobip\Observer\QueueOptinConfirmation;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class QueueOptinConfirmationTest extends TestCase
{
    private array $published = [];
    private bool $enabled = true;
    private ?string $provider = 'infobip_sms';

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
        $queue->method('publish')->willReturnCallback(function (...$args) { $this->published[] = $args; });
        (new QueueOptinConfirmation($config, $providers, $queue))->execute(new Observer($data + ['subscription_id' => 9,
            'channel' => 'sms', 'store_id' => 1, 'contact_id' => 7, 'first_for_address' => true]));
    }

    public function testTheFirstSignUpOfANumberIsConfirmedWhenInfobipSendsIt(): void
    {
        $this->observe([]);
        $this->observe(['channel' => 'viber']);
        $this->assertSame([['infobip_optin', '9'], ['infobip_optin', '9']], $this->published);
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
