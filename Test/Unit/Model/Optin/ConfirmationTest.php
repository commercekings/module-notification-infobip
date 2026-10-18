<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Test\Unit\Model\Optin;

use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\Subscription;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use CommerceKings\Notification\Api\SenderInterface;
use CommerceKings\Notification\Api\SubscriptionRepositoryInterface;
use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\NotificationInfobip\Model\Config;
use CommerceKings\NotificationInfobip\Model\Optin\Confirmation;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

class ConfirmationTest extends TestCase
{
    private array $recipient = ['channel' => 'sms', 'recipient' => '+381601234567'];
    /** rows the claim UPDATE changes (1: claimed, 0: already confirmed) */
    private int $claim = 1;
    private array $updates = [];
    private array $sent = [];
    private ?\Throwable $failure = null;

    private function confirmation(?Subscription $subscription = null): Confirmation
    {
        $subscriptions = $this->createStub(SubscriptionRepositoryInterface::class);
        $subscriptions->method('get')->willReturn($subscription
            ?? new Subscription(9, 'cart_reminder_sms', 42, 1, 7, null, 'consent-1', '2026-10-18 12:00:00'));
        $subscriptions->method('getRecipient')->willReturnCallback(fn () => $this->recipient);
        $state = $this->createStub(DeliveryStateInterface::class);
        $state->method('getTableName')->willReturn('ck_notification_delivery_state');
        $state->method('keyCondition')->willReturnCallback(fn ($type, $id) => ['type = ?' => $type, 'reference_id = ?' => $id,
            'subject_key = ?' => '']);
        $sender = $this->createStub(SenderInterface::class);
        $sender->method('send')->willReturnCallback(function (Delivery $delivery) {
            if ($this->failure) {
                throw $this->failure;
            }
            $this->sent[] = [$delivery, ($delivery->content)()];
            return SenderInterface::SENT;
        });
        $config = $this->createStub(Config::class);
        $config->method('getOptinText')->willReturn('{{store}}: you are signed up. Reply STOP to stop.');
        $config->method('getStoreName')->willReturn('Acme');
        $db = $this->createStub(AdapterInterface::class);
        $db->method('update')->willReturnCallback(function ($table, $data, $where) {
            $this->updates[] = [$data, $where];
            return isset($data['sent_at']) && $data['sent_at'] !== null ? $this->claim : 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        return new Confirmation($subscriptions, $state, $sender, $config, $resource);
    }

    public function testOneServiceMessagePerNumberClaimedInTheDeliveryState(): void
    {
        $this->confirmation()->process('9');
        $this->assertCount(1, $this->sent);
        [$delivery, $content] = $this->sent[0];
        $this->assertSame(['sms', '+381601234567', false, false, 'infobip_optin'],
            [$delivery->channel, $delivery->recipient, $delivery->useCooldown, $delivery->stoppable, $delivery->type]);
        $this->assertSame('Acme: you are signed up. Reply STOP to stop.', $content->text);
        $this->assertSame(['store' => 'Acme'], $content->vars, 'WhatsApp\'s template variable');
        $this->assertSame(['type = ?' => 'infobip_optin', 'reference_id = ?' => 7, 'subject_key = ?' => '', 'sent_at IS NULL'],
            $this->updates[0][1], 'keyed by the number\'s contact: once per number, also with two sign-ups at once');
    }

    public function testAlreadyConfirmedSendsNothing(): void
    {
        $this->claim = 0;
        $this->confirmation()->process('9');
        $this->assertSame([], $this->sent);
    }

    public function testAFailedSendReleasesTheClaimButOneThatWentOutKeepsIt(): void
    {
        $this->failure = new \RuntimeException('Infobip down');
        try {
            $this->confirmation()->process('9');
        } catch (\RuntimeException) {
        }
        $this->assertSame(['sent_at' => null], end($this->updates)[0], 'released: a later sign-up may confirm it');
        $this->updates = [];
        $this->failure = new MessageSentException('maybe sent');
        try {
            $this->confirmation()->process('9');
        } catch (MessageSentException) {
        }
        $this->assertCount(1, $this->updates, 'kept: never twice');
    }

    public function testOnlyPhoneChannelsOfAKnownContact(): void
    {
        $this->recipient = ['channel' => 'email', 'recipient' => 'a@test.com'];
        $this->confirmation()->process('9');
        $this->recipient = ['channel' => 'viber', 'recipient' => '+381601234567'];
        $this->confirmation(new Subscription(9, 't', 42, 1, null, 5, 'c', '2026-10-18 12:00:00'))->process('9');
        $this->assertSame([], $this->sent);
        $this->assertSame([], $this->updates);
    }
}
