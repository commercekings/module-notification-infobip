<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Model\Webhook;

use CommerceKings\Notification\Api\StopListInterface;
use CommerceKings\NotificationInfobip\Model\MessageSender;
use Psr\Log\LoggerInterface;

/**
 * Delivery reports ({"results": […]}): a message the person blocked or opted out of is their opt-out
 * (StopListInterface::REASON_REPLY); one that failed for good - Infobip marks the error permanent (an unknown or
 * absent number, a landline, no account on the channel) - suppresses the number (REASON_UNDELIVERABLE: its consents
 * stay, it lifts after the configured days). Delivered, pending, expired and temporary failures change nothing.
 */
class ReportProcessor
{
    /** Infobip's status groups of a message that did not and will not arrive. */
    public const FAILED_GROUPS = ['UNDELIVERABLE', 'REJECTED'];

    public function __construct(
        private readonly StopListInterface $stopList,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $payload the webhook's JSON
     * @return array<int, string|null> per result: the stop reason applied, or null
     */
    public function process(array $payload, ?string $channel): array
    {
        $done = [];
        foreach ((array)($payload['results'] ?? []) as $result) {
            $done[] = is_array($result) ? $this->one($result, $channel) : null;
        }
        return $done;
    }

    /** @param array<string, mixed> $result */
    private function one(array $result, ?string $hint): ?string
    {
        $channel = Address::channel($hint, $result);
        $reason = $this->reason($result);
        if ($channel === null || $reason === null) {
            return null;
        }
        $to = Address::number((string)($result['to'] ?? $result['destination'] ?? ''));
        try {
            $this->stopList->stop($channel, $to, $reason);
        } catch (\InvalidArgumentException) {
            $this->logger->warning(sprintf('CommerceKings_NotificationInfobip: a delivery report for an address that is'
                . ' not a valid number on the "%s" channel was ignored.', $channel));
            return null;
        }
        return $reason;
    }

    /** @param array<string, mixed> $result */
    private function reason(array $result): ?string
    {
        $status = is_array($result['status'] ?? null) ? $result['status'] : [];
        $error = is_array($result['error'] ?? null) ? $result['error'] : [];
        if (!in_array(strtoupper((string)($status['groupName'] ?? '')), self::FAILED_GROUPS, true)) {
            return null;
        }
        if (MessageSender::isOptOut((string)($status['name'] ?? '')) || MessageSender::isOptOut((string)($error['name'] ?? ''))) {
            return StopListInterface::REASON_REPLY;
        }
        return ($error['permanent'] ?? false) === true ? StopListInterface::REASON_UNDELIVERABLE : null;
    }
}
