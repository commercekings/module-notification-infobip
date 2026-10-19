<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Model\Webhook;

use CommerceKings\Notification\Api\StopListInterface;
use Psr\Log\LoggerInterface;

/**
 * Messages to the shop's senders (Infobip forwards them as {"results": […]}): STOP and its synonyms ("opt out",
 * "STOP!!" too; see keyword()), WhatsApp's "Stop promotions" button and a Viber unsubscribe stop the number on its channel until it sends START
 * (StopListInterface::REASON_REPLY); START and its synonyms (and a Viber subscribe) lift it - removed subscriptions do
 * not come back. Anything else is left alone.
 *
 * Infobip retries a forward for up to two days while the shop does not answer, so replies can arrive out of order:
 * each one carries its receivedAt, and a START lifts only a stop older than it (StopListInterface::resume()) - a late
 * START never undoes a newer STOP. Without a readable receivedAt the reply counts as received now.
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
            if ($action === '') {
                $this->logger->info('CommerceKings_NotificationInfobip: unrecognized reply.'); // no number, no text
            }
            return '';
        }
        $from = Address::number((string)($result['from'] ?? $result['sender'] ?? ''));
        try {
            $receivedAt = $this->receivedAt($result);
            if ($action === 'stop') {
                $this->stopList->stop($channel, $from, StopListInterface::REASON_REPLY, $receivedAt);
            } else {
                $this->stopList->resume($channel, $from, $receivedAt);
            }
        } catch (\InvalidArgumentException) {
            // not a number Notification can take: nothing to stop; Infobip still gets 200 (a retry cannot succeed)
            $this->logger->warning(sprintf('CommerceKings_NotificationInfobip: a %s message from an address that is not a'
                . ' valid number on the "%s" channel was ignored.', strtoupper($action), $channel));
            return '';
        }
        return $action;
    }

    /**
     * When Infobip received the reply (e.g. "2026-10-19T10:00:05.123+0000"); null when missing or unreadable.
     *
     * @param array<string, mixed> $result
     */
    private function receivedAt(array $result): ?\DateTimeImmutable
    {
        $value = $result['receivedAt'] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return null;
        }
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
        $texts = $this->texts($result, $message);
        foreach ([$message['payload'] ?? '', ...$texts] as $value) {
            if (strtolower(trim((string)$value)) === self::STOP_PROMOTIONS) {
                return 'stop';
            }
        }
        foreach ($texts as $text) {
            $keyword = $this->keyword($text);
            if ($keyword !== '') {
                return $keyword;
            }
        }
        return '';
    }

    /**
     * The reply's texts, each checked whole: the full text, Infobip's keyword with the text after it, and the text after
     * the keyword (cleanText). With forwarding set up for a keyword, a bare "STOP" arrives with an empty cleanText, and
     * "SHOP STOP" (keyword SHOP) with cleanText "STOP" - either way the reply is not lost.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $message
     * @return string[]
     */
    private function texts(array $result, array $message): array
    {
        $keyword = trim((string)($result['keyword'] ?? ''));
        $clean = (string)($result['cleanText'] ?? '');
        $texts = [(string)($result['text'] ?? ''), (string)($message['text'] ?? ''),
            $keyword !== '' ? $keyword . ' ' . $clean : '', $clean];
        return array_values(array_filter($texts, fn (string $text) => trim($text) !== ''));
    }

    /**
     * The keyword of a reply: the whole message is exactly a keyword, ignoring case, spaces, hyphens, punctuation and
     * emoji ("Opt-out!" is OPTOUT, "stop all" STOPALL) - the industry rule; "stop please" is none (the provider's own
     * opt-out handling and the merchant cover those).
     */
    private function keyword(string $text): string
    {
        $word = (string)preg_replace('/[^\p{L}]+/u', '', mb_strtoupper($text, 'UTF-8'));
        return match (true) {
            in_array($word, self::STOP_WORDS, true) => 'stop',
            in_array($word, self::START_WORDS, true) => 'start',
            default => '',
        };
    }
}
