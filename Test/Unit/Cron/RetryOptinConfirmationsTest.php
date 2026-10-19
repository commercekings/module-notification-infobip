<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Test\Unit\Cron;

use CommerceKings\Notification\Api\QueueInterface;
use CommerceKings\NotificationInfobip\Cron\RetryOptinConfirmations;
use CommerceKings\NotificationInfobip\Model\Optin\Pending;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/** Due confirmations are published again, each by the run that claims it; interrupted sends are reported, not resent. */
class RetryOptinConfirmationsTest extends TestCase
{
    private array $published = [];
    private array $calls = [];
    private array $warnings = [];
    private array $won = [7 => true, 8 => false, 9 => true];
    private ?\Throwable $brokerDown = null;
    private int $interrupted = 0;

    private function runCron(): int
    {
        $pending = $this->createStub(Pending::class);
        $pending->method('due')->willReturn([7, 8, 9]);
        $pending->method('claim')->willReturnCallback(fn (int $id) => $this->won[$id]);
        $pending->method('notQueued')->willReturnCallback(function (int $id) { $this->calls[] = ['notQueued', $id]; });
        $pending->method('interrupted')->willReturnCallback(fn () => $this->interrupted);
        $queue = $this->createStub(QueueInterface::class);
        $queue->method('publish')->willReturnCallback(function ($type, $reference) {
            if ($this->brokerDown) {
                throw $this->brokerDown;
            }
            $this->published[] = [$type, $reference];
        });
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function ($message) { $this->warnings[] = $message; });
        return (new RetryOptinConfirmations($pending, $queue, $logger))->execute();
    }

    public function testDueConfirmationsAreQueuedAgainOnlyByTheRunThatClaimsThem(): void
    {
        $this->assertSame(2, $this->runCron());
        $this->assertSame([['infobip_optin', '7'], ['infobip_optin', '9']], $this->published, '8 was taken by another run');
        $this->assertSame([], $this->warnings);
    }

    public function testABrokerThatIsDownStopsTheRunAndTheNextOneRetries(): void
    {
        $this->brokerDown = new \RuntimeException('down');
        $this->assertSame(0, $this->runCron());
        $this->assertSame([['notQueued', 7]], $this->calls, 'given back; 9 is not tried against a dead broker');
        $this->assertCount(1, $this->warnings);
    }

    public function testInterruptedSendsAreReportedNotResent(): void
    {
        $this->won = [7 => false, 8 => false, 9 => false];
        $this->interrupted = 2;
        $this->runCron();
        $this->assertSame([], $this->published);
        $this->assertStringContainsString('2 opt-in confirmation(s) were interrupted while sending', $this->warnings[0]);
    }
}
