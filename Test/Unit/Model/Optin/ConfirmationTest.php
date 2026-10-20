<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Test\Unit\Model\Optin;

use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\Subscription;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use CommerceKings\Notification\Api\SenderInterface;
use CommerceKings\Notification\Api\SubscriptionRepositoryInterface;
use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\Notification\Exception\TemporaryFailureException;
use CommerceKings\NotificationInfobip\Model\Config;
use CommerceKings\NotificationInfobip\Model\Optin\Confirmation;
use CommerceKings\NotificationInfobip\Model\Optin\Pending;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * One confirmation per number and website, ever: claimed in Notification's delivery state before it is sent. Never
 * lost: what fails before Infobip took it is retried (Pending); what may be out keeps its claim.
 */
class ConfirmationTest extends TestCase
{
    private array $updates = [];
    private int $claimResult = 1;
    private array $deliveries = [];
    private ?\Throwable $sendFailure = null;
    private ?Subscription $subscription;
    /** @var array<int, array> calls to Pending */
    private array $pending = [];
    private bool $waiting = false;
    private bool $retriesLeft = true;
    private array $errors = [];
    /** the number's confirmation row as a lost claim reads it: confirmed (sent, not sending) by default */
    private ?array $stateRow = ['sent_at' => '2026-10-18 10:00:00', 'queued_at' => null];
    private array $recipient = ['channel' => 'sms', 'recipient' => '+381601234567'];

    protected function setUp(): void
    {
        $this->subscription = new Subscription(7, 'cart_reminder_sms', 42, 1, 5, null, 'ac:x', '2026-10-18 10:00:00');
    }

    private function confirmation(): Confirmation
    {
        $repository = $this->createStub(SubscriptionRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(fn () => $this->subscription);
        $repository->method('getRecipient')->willReturnCallback(fn () => $this->recipient);
        $state = $this->createStub(DeliveryStateInterface::class);
        $state->method('getTableName')->willReturn('ck_notification_delivery_state');
        $state->method('load')->willReturnCallback(fn () => $this->stateRow);
        $state->method('keyCondition')->willReturnCallback(fn ($type, $id) => ['type = ?' => $type, 'reference_id = ?' => $id, "subject_key = ''"]);
        $sender = $this->createStub(SenderInterface::class);
        $sender->method('send')->willReturnCallback(function (Delivery $delivery) {
            $this->deliveries[] = $delivery;
            if ($this->sendFailure) {
                throw $this->sendFailure;
            }
            return SenderInterface::SENT;
        });
        $config = $this->createStub(Config::class);
        $config->method('getOptinText')->willReturn('{{store}}: you are signed up. Reply STOP to stop.');
        $config->method('getStoreName')->willReturn('Acme');
        $db = $this->createStub(AdapterInterface::class);
        $db->method('update')->willReturnCallback(function ($table, $data, $where) {
            $this->updates[] = [array_map(fn ($value) => is_object($value) ? (string)$value : $value, $data), $where];
            return count($this->updates) === 1 ? $this->claimResult : 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $pending = $this->createStub(Pending::class);
        $pending->method('isWaiting')->willReturnCallback(fn () => $this->waiting);
        $pending->method('done')->willReturnCallback(function (int $id) { $this->pending[] = ['done', $id]; });
        $pending->method('failed')->willReturnCallback(function (int $id, ?int $wait = null) {
            $this->pending[] = ['failed', $id, $wait];
            return $this->retriesLeft;
        });
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function ($message) { $this->errors[] = $message; });
        return new Confirmation($repository, $state, $sender, $config, $resource, $pending, $logger);
    }

    private function process(): ?\Throwable
    {
        try {
            $this->confirmation()->process('7');
        } catch (\Throwable $error) {
            return $error;
        }
        return null;
    }

    public function testTheClaimedNumberGetsOneTextThatCannotBeStoppedOrHeld(): void
    {
        $this->assertNull($this->process());
        $this->assertContains('sent_at IS NULL', $this->updates[0][1], 'claimed only if never sent');
        $this->assertSame(5, $this->updates[0][1]['reference_id = ?'], 'keyed by the number contact');
        $this->assertSame('NOW()', $this->updates[0][0]['queued_at'], 'marked as sending while it is sent');
        $delivery = $this->deliveries[0];
        $this->assertSame(['sms', '+381601234567', false, false, 'infobip_optin'],
            [$delivery->channel, $delivery->recipient, $delivery->useCooldown, $delivery->stoppable, $delivery->type]);
        $this->assertSame('Acme: you are signed up. Reply STOP to stop.', ($delivery->content)()->text);
        $this->assertSame([['queued_at' => null], ['type = ?' => 'infobip_optin', 'reference_id = ?' => 5, "subject_key = ''"]],
            $this->updates[1], 'sent: the claim stays, the sending mark goes');
        $this->assertSame([['done', 7]], $this->pending);
    }

    public function testAlreadyConfirmedOrGoneSendsNothingAndEndsThePendingRow(): void
    {
        $this->claimResult = 0;
        $this->process();
        $this->subscription = null;
        $this->process();
        $this->assertSame([], $this->deliveries);
        $this->assertSame([['done', 7], ['done', 7]], $this->pending);
    }

    /**
     * A duplicate that loses the claim while the owner is still sending (or after the owner gave it back to retry)
     * must not end the pending row: that row is the owner's retry, and without it the confirmation is never sent.
     */
    public function testALostClaimEndsThePendingRowOnlyWhenTheConfirmationIsComplete(): void
    {
        $this->claimResult = 0;
        foreach (['the owner is sending' => ['sent_at' => '2026-10-18 10:00:00', 'queued_at' => '2026-10-18 10:00:00'],
            'the owner gave it back to retry' => ['sent_at' => null, 'queued_at' => null],
            'no row' => null] as $case => $row) {
            $this->stateRow = $row;
            $this->updates = []; // the claim is the first update: refused
            $this->process();
            $this->assertSame([], $this->pending, $case . ': the pending row stays');
        }
        $this->assertSame([], $this->deliveries);
    }

    public function testADuplicateBeforeTheScheduledRetryLeavesItToTheRetry(): void
    {
        $this->waiting = true;
        $this->process();
        $this->assertSame([[], []], [$this->deliveries, $this->updates], 'no claim, no send');
    }

    public function testATemporaryRefusalReleasesTheClaimAndWaitsAsTwilioAsks(): void
    {
        $this->sendFailure = new TemporaryFailureException('429', 120);
        $this->assertNull($this->process(), 'handled: not rethrown');
        $this->assertSame(['sent_at' => null, 'queued_at' => null], $this->updates[1][0], 'released');
        $this->assertSame([['failed', 7, 120]], $this->pending);
        $this->pending = [];
        $this->updates = [];
        $this->sendFailure = new TemporaryFailureException('503 without Retry-After');
        $this->process();
        $this->assertSame([['failed', 7, null]], $this->pending, 'without a Retry-After: the growing wait');
    }

    public function testAFailureBeforeInfobipTookItIsRetriedAndRethrown(): void
    {
        $this->sendFailure = new \RuntimeException('Infobip unreachable');
        $this->assertSame($this->sendFailure, $this->process());
        $this->assertSame(['sent_at' => null, 'queued_at' => null], $this->updates[1][0], 'released');
        $this->assertSame([['failed', 7, null]], $this->pending);
        $this->assertSame([], $this->errors);
    }

    public function testAfterTheLastAttemptItIsGivenUpAndLoggedWithoutTheNumber(): void
    {
        $this->retriesLeft = false;
        $this->sendFailure = new \RuntimeException('Infobip unreachable');
        $this->process();
        $this->assertCount(1, $this->errors);
        $this->assertStringContainsString('subscription 7 was given up after 8 failed attempts', $this->errors[0]);
        $this->assertStringNotContainsString('381601234567', $this->errors[0]);
    }

    public function testAMessageThatMayBeOutKeepsItsClaimAndIsNeverRetried(): void
    {
        $this->sendFailure = new MessageSentException('no answer');
        $this->assertInstanceOf(MessageSentException::class, $this->process());
        $this->assertSame(['queued_at' => null], $this->updates[1][0], 'the claim (sent_at) stays');
        $this->assertCount(2, $this->updates);
        $this->assertSame([['done', 7]], $this->pending);
    }

    public function testViberToo(): void
    {
        $this->recipient = ['channel' => 'viber', 'recipient' => '+381601234567'];
        $this->process();
        $this->assertSame('viber', $this->deliveries[0]->channel);
        $this->assertSame(['store' => 'Acme'], ($this->deliveries[0]->content)()->vars, 'WhatsApp\'s template variable');
    }

    public function testOnlyChannelsOfThisModule(): void
    {
        $this->recipient = ['channel' => 'email', 'recipient' => 'a@test.com'];
        $this->process();
        $this->assertSame([[], []], [$this->deliveries, $this->updates]);
        $this->assertSame([['done', 7]], $this->pending);
    }

    public function testAnInvalidNumberIsNotRetried(): void
    {
        $this->sendFailure = new \InvalidArgumentException('not a number');
        $this->assertInstanceOf(\InvalidArgumentException::class, $this->process());
        $this->assertSame(['sent_at' => null, 'queued_at' => null], $this->updates[1][0]);
        $this->assertSame([['done', 7]], $this->pending);
    }
}
