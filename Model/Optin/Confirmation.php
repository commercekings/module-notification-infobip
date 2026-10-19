<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Model\Optin;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use CommerceKings\Notification\Api\NotificationTypeInterface;
use CommerceKings\Notification\Api\SenderInterface;
use CommerceKings\Notification\Api\SubscriptionRepositoryInterface;
use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\Notification\Exception\TemporaryFailureException;
use CommerceKings\NotificationInfobip\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;
use Psr\Log\LoggerInterface;

/**
 * The opt-in confirmation (Notification type "infobip_optin", reference = the subscription id): one message per number
 * and website - the claim is one row in Notification's delivery state (type infobip_optin, reference = the number's
 * contact id), so a retry, a second sign-up at the same moment or a second worker never sends it twice. Not
 * stoppable, no cooldown: the person just asked for it, and it must not hold back their first real message. SMS and
 * Viber get the text; WhatsApp sends the template mapped for "infobip_optin" (variable "store").
 *
 * Never lost, never twice (Pending): a send that fails before Infobip took it - refused for now (Retry-After), Infobip
 * unreachable, any error - gives the claim back and is retried later, up to Pending::MAX_ATTEMPTS, then logged as
 * given up. One that may be out (MessageSentException) keeps its claim. While sending, the claim row's queued_at is
 * set: a claim left so by a dead process is reported by Pending::interrupted(), not resent.
 */
class Confirmation implements NotificationTypeInterface
{
    public const TYPE = 'infobip_optin';
    public const CHANNELS = [Channel::SMS, Channel::WHATSAPP, Channel::VIBER];

    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptions,
        private readonly DeliveryStateInterface $state,
        private readonly SenderInterface $sender,
        private readonly Config $config,
        private readonly ResourceConnection $resource,
        private readonly Pending $pending,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(string $reference): void
    {
        $subscriptionId = (int)$reference;
        $subscription = $this->subscriptions->get($subscriptionId);
        $recipient = $subscription !== null ? $this->subscriptions->getRecipient($subscription) : null;
        if ($subscription === null || $subscription->contactId === null || $recipient === null
            || !in_array($recipient['channel'], self::CHANNELS, true)) {
            $this->pending->done($subscriptionId);
            return; // gone (unsubscribed or stopped) or not a channel of this module
        }
        if ($this->pending->isWaiting($subscriptionId)) {
            return; // a duplicate before the scheduled retry: the retry sends it
        }
        $contactId = $subscription->contactId;
        $this->state->ensure(self::TYPE, $contactId);
        $db = $this->resource->getConnection();
        $claimed = $db->update($this->state->getTableName(), ['sent_at' => new Expression('NOW()'),
            'queued_at' => new Expression('NOW()'), 'delivery_version' => new Expression('delivery_version + 1')],
            array_merge($this->state->keyCondition(self::TYPE, $contactId), ['sent_at IS NULL']));
        if (!$claimed) {
            $this->pending->done($subscriptionId);
            return; // already confirmed (or another worker is sending it)
        }
        $storeId = $subscription->storeId;
        $store = $this->config->getStoreName($storeId);
        $text = strtr($this->config->getOptinText($storeId), ['{{store}}' => $store]);
        try {
            $this->sender->send(Delivery::text($storeId, $recipient['channel'], $recipient['recipient'],
                fn () => new DeliveryContent(['store' => $store], text: $text),
                false, null, false, self::TYPE));
        } catch (MessageSentException $sent) {
            $this->finish($contactId, $subscriptionId);
            throw $sent; // it is out: keep the claim
        } catch (TemporaryFailureException $refused) {
            // refused for now (a rate limit, a busy provider): nothing was sent - try again when the provider says
            $this->release($contactId);
            $this->retry($subscriptionId, $refused->getRetryAfter());
            return;
        } catch (\InvalidArgumentException $invalid) {
            // the number is not valid for the channel: a retry cannot help
            $this->release($contactId);
            $this->pending->done($subscriptionId);
            throw $invalid;
        } catch (\Throwable $error) {
            // not sent (Infobip unreachable, refused, …): give the claim back and try again later
            $this->release($contactId);
            $this->retry($subscriptionId, null);
            throw $error;
        }
        $this->finish($contactId, $subscriptionId);
    }

    /** Sent (or may be): the claim stays, the sending mark and the pending row go. */
    private function finish(int $contactId, int $subscriptionId): void
    {
        $this->resource->getConnection()->update($this->state->getTableName(), ['queued_at' => null],
            $this->state->keyCondition(self::TYPE, $contactId));
        $this->pending->done($subscriptionId);
    }

    /** Not sent: the number may be confirmed again. */
    private function release(int $contactId): void
    {
        $this->resource->getConnection()->update($this->state->getTableName(), ['sent_at' => null, 'queued_at' => null],
            $this->state->keyCondition(self::TYPE, $contactId));
    }

    private function retry(int $subscriptionId, ?int $retryAfter): void
    {
        if (!$this->pending->failed($subscriptionId, $retryAfter)) {
            // no number in the log
            $this->logger->error(sprintf('CommerceKings_NotificationInfobip: the opt-in confirmation of subscription %d'
                . ' was given up after %d failed attempts.', $subscriptionId, Pending::MAX_ATTEMPTS));
        }
    }
}
