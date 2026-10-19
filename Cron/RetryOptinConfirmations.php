<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Cron;

use CommerceKings\Notification\Api\QueueInterface;
use CommerceKings\NotificationInfobip\Model\Optin\Confirmation;
use CommerceKings\NotificationInfobip\Model\Optin\Pending;
use Psr\Log\LoggerInterface;

/**
 * Every 5 minutes: publishes again the opt-in confirmations that are due (Pending: a failed send after its wait, a
 * message the broker or a consumer lost), each claimed first so overlapping runs publish it once, and reports
 * confirmations interrupted while sending (not resent: they may be out).
 */
class RetryOptinConfirmations
{
    private const BATCH = 100;

    public function __construct(
        private readonly Pending $pending,
        private readonly QueueInterface $queue,
        private readonly LoggerInterface $logger
    ) {
    }

    /** @return int confirmations published again */
    public function execute(): int
    {
        $published = 0;
        foreach ($this->pending->due(self::BATCH) as $subscriptionId) {
            if (!$this->pending->claim($subscriptionId)) {
                continue; // another run took it
            }
            try {
                $this->queue->publish(Confirmation::TYPE, (string)$subscriptionId);
                $published++;
            } catch (\Throwable $error) {
                $this->pending->notQueued($subscriptionId);
                $this->logger->warning(sprintf('CommerceKings_NotificationInfobip: opt-in confirmations could not be'
                    . ' queued again (%s); the next run retries.', get_class($error)));
                break; // the broker is down: the rest waits for the next run
            }
        }
        $interrupted = $this->pending->interrupted();
        if ($interrupted) {
            $this->logger->warning(sprintf('CommerceKings_NotificationInfobip: %d opt-in confirmation(s) were interrupted'
                . ' while sending; they may not have arrived and are not sent again.', $interrupted));
        }
        return $published;
    }
}
