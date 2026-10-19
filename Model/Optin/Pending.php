<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Model\Optin;

use CommerceKings\Notification\Api\DeliveryStateInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/**
 * Opt-in confirmations still to be sent, so none is lost: one row in Notification's delivery state per sign-up (type
 * TYPE, reference = the subscription id the queue message carries) from the moment it is queued until it is sent,
 * found already confirmed, no longer wanted or given up. Cron\RetryOptinConfirmations publishes again what is due: a
 * confirmation that failed before Infobip took it (after a growing wait or the provider's Retry-After; MAX_ATTEMPTS at
 * most) and one whose message got lost (queued STUCK_MINUTES ago without an outcome: the broker or a consumer failed).
 * A confirmation interrupted after its claim is never sent again: it may be out (see interrupted()).
 */
class Pending
{
    public const TYPE = Confirmation::TYPE . '_due';
    /** Failed sends before giving up (about 5 hours with the growing wait). */
    public const MAX_ATTEMPTS = 8;
    /** A queued confirmation without an outcome after this is published again. */
    public const STUCK_MINUTES = 60;
    /** The first wait after a failure; it doubles with each attempt, up to MAX_RETRY_SECONDS. */
    public const RETRY_SECONDS = 300;
    public const MAX_RETRY_SECONDS = 3600;
    /** A provider's Retry-After is kept within these bounds. */
    public const MIN_WAIT_SECONDS = 60;
    public const MAX_WAIT_SECONDS = 86400;

    private const DUE = '((queued_at IS NULL AND (skip_until IS NULL OR skip_until <= NOW()))'
        . ' OR queued_at < NOW() - INTERVAL ' . self::STUCK_MINUTES . ' MINUTE)';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DeliveryStateInterface $state
    ) {
    }

    /** Published now: waiting in the queue. */
    public function queued(int $subscriptionId): void
    {
        $this->state->ensure(self::TYPE, $subscriptionId);
        $this->update($subscriptionId, ['queued_at' => new Expression('NOW()'), 'skip_until' => null]);
    }

    /** Publishing failed: due again in RETRY_SECONDS, not counted as an attempt. */
    public function notQueued(int $subscriptionId): void
    {
        $this->state->ensure(self::TYPE, $subscriptionId);
        $this->update($subscriptionId, ['queued_at' => null,
            'skip_until' => new Expression('NOW() + INTERVAL ' . self::RETRY_SECONDS . ' SECOND')]);
    }

    /** A retry is scheduled for later: a message that arrives before it (a duplicate) leaves it to the retry. */
    public function isWaiting(int $subscriptionId): bool
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->from($this->state->getTableName(), new Expression('1'))->where('skip_until > NOW()');
        foreach ($this->state->keyCondition(self::TYPE, $subscriptionId) as $condition => $value) {
            is_int($condition) ? $select->where($value) : $select->where($condition, $value);
        }
        return (bool)$db->fetchOne($select);
    }

    /** Sent, already confirmed, or no longer wanted (the subscription is gone). */
    public function done(int $subscriptionId): void
    {
        $this->resource->getConnection()->delete($this->state->getTableName(),
            $this->state->keyCondition(self::TYPE, $subscriptionId));
    }

    /**
     * Not sent: due again after the provider's wait (Retry-After, within the bounds), else a wait that grows with each
     * attempt.
     *
     * @return bool false when given up after MAX_ATTEMPTS (the row is removed)
     */
    public function failed(int $subscriptionId, ?int $retryAfter = null): bool
    {
        $this->state->ensure(self::TYPE, $subscriptionId);
        $attempts = (int)($this->state->load(self::TYPE, $subscriptionId)['failed_sends'] ?? 0) + 1;
        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->done($subscriptionId);
            return false;
        }
        $wait = $retryAfter !== null ? min(self::MAX_WAIT_SECONDS, max(self::MIN_WAIT_SECONDS, $retryAfter))
            : min(self::MAX_RETRY_SECONDS, self::RETRY_SECONDS * 2 ** ($attempts - 1));
        $this->update($subscriptionId, ['failed_sends' => $attempts, 'queued_at' => null,
            'skip_until' => new Expression('NOW() + INTERVAL ' . $wait . ' SECOND')]);
        return true;
    }

    /**
     * Subscription ids due for publishing: their wait is over, or their message got lost.
     *
     * @return int[]
     */
    public function due(int $limit = 100): array
    {
        $db = $this->resource->getConnection();
        return array_map('intval', $db->fetchCol($db->select()->from($this->state->getTableName(), 'reference_id')
            ->where('type = ?', self::TYPE)->where(self::DUE)->order('updated_at ASC')->limit($limit)));
    }

    /** Takes one due confirmation for publishing: true for the run that won it (never two at once). */
    public function claim(int $subscriptionId): bool
    {
        return $this->update($subscriptionId, ['queued_at' => new Expression('NOW()')], [self::DUE]) > 0;
    }

    /**
     * Confirmations claimed STUCK_MINUTES ago that never finished (the process died while sending): they may be out,
     * so they are not sent again - only counted and closed for the log.
     */
    public function interrupted(): int
    {
        return $this->resource->getConnection()->update($this->state->getTableName(), ['queued_at' => null], [
            'type = ?' => Confirmation::TYPE, 'sent_at IS NOT NULL',
            'queued_at < NOW() - INTERVAL ' . self::STUCK_MINUTES . ' MINUTE',
        ]);
    }

    private function update(int $subscriptionId, array $data, array $conditions = []): int
    {
        return $this->resource->getConnection()->update($this->state->getTableName(), $data,
            array_merge($this->state->keyCondition(self::TYPE, $subscriptionId), $conditions));
    }
}
