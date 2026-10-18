<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Model\Webhook;

use CommerceKings\Notification\Api\StopListInterface;
use Psr\Log\LoggerInterface;

/**
 * Messages to the shop's senders (Infobip forwards them as {"results": […]}): STOP and its synonyms, WhatsApp's "Stop
 * promotions" button and a Viber unsubscribe stop the number on its channel until it sends START
 * (StopListInterface::REASON_REPLY); START and its synonyms (and a Viber subscribe) lift it - removed subscriptions do
 * not come back. Anything else is left alone.
 */
class InboundProcessor
{
    public const STOP_WORDS = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'REVOKE', 'OPTOUT'];
    public const START_WORDS = ['START', 'UNSTOP', 'YES'];
    /** The opt-out button of WhatsApp marketing templates. */
    public const STOP_PROMOTIONS = 'stop promotions';
    /** Viber's subscription events (the user unsubscribed from or subscribed to the sender). */
    public const UNSUBSCRIBED = ['UNSUBSCRIBE', 'UNSUBSCRIBED'];
    public const SUBSCRIBED = ['SUBSCRIBE', 'SUBSCRIBED'];

    public function __construct(
        private readonly StopListInterface $stopList,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $payload the webhook's JSON
     * @param string|null $channel the channel the webhook URL names
     * @return array<int, string> per result: "stop", "start" or "" (nothing to do)
     */
    public function process(array $payload, ?string $channel): array
    {
        $done = [];
        foreach ((array)($payload['results'] ?? []) as $result) {
            $done[] = is_array($result) ? $this->one($result, $channel) : '';
        }
        return $done;
    }

    /** @param array<string, mixed> $result */
    private function one(array $result, ?string $hint): string
    {
        $channel = Address::channel($hint, $result);
        $action = $this->action($result);
        if ($channel === null || $action === '') {
            return '';
        }
        $from = Address::number((string)($result['from'] ?? $result['sender'] ?? ''));
        try {
            if ($action === 'stop') {
                $this->stopList->stop($channel, $from, StopListInterface::REASON_REPLY);
            } else {
                $this->stopList->resume($channel, $from);
            }
        } catch (\InvalidArgumentException) {
            // not a number Notification can take: nothing to stop; Infobip still gets 200 (a retry cannot succeed)
            $this->logger->warning(sprintf('CommerceKings_NotificationInfobip: a %s message from an address that is not a'
                . ' valid number on the "%s" channel was ignored.', strtoupper($action), $channel));
            return '';
        }
        return $action;
    }

    /** @param array<string, mixed> $result */
    private function action(array $result): string
    {
        $message = is_array($result['message'] ?? null) ? $result['message'] : [];
        foreach ([$result['event'] ?? '', $message['event'] ?? '', $message['type'] ?? ''] as $event) {
            $event = strtoupper(trim((string)$event));
            if (in_array($event, self::UNSUBSCRIBED, true)) {
                return 'stop';
            }
            if (in_array($event, self::SUBSCRIBED, true)) {
                return 'start';
            }
        }
        $text = (string)($result['cleanText'] ?? $result['text'] ?? $message['text'] ?? '');
        foreach ([$message['payload'] ?? '', $message['text'] ?? '', $text] as $value) {
            if (strtolower(trim((string)$value)) === self::STOP_PROMOTIONS) {
                return 'stop';
            }
        }
        $word = strtoupper(trim((string)preg_replace('/[^\p{L}]+/u', ' ', $text)));
        return match (true) {
            in_array($word, self::STOP_WORDS, true) => 'stop',
            in_array($word, self::START_WORDS, true) => 'start',
            default => '',
        };
    }
}
