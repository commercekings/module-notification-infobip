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
use CommerceKings\NotificationInfobip\Model\Config;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Sql\Expression;

/**
 * The opt-in confirmation (Notification type "infobip_optin", reference = the subscription id): one message per number
 * and website - the claim is one row in Notification's delivery state (type infobip_optin, reference = the number's
 * contact id), so a retry, a second sign-up at the same moment or a second worker never sends it twice. Not
 * stoppable, no cooldown: the person just asked for it, and it must not hold back their first real message. SMS and
 * Viber get the text; WhatsApp sends the template mapped for "infobip_optin" (variable "store").
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
        private readonly ResourceConnection $resource
    ) {
    }

    public function process(string $reference): void
    {
        $subscription = $this->subscriptions->get((int)$reference);
        $recipient = $subscription !== null ? $this->subscriptions->getRecipient($subscription) : null;
        if ($subscription === null || $subscription->contactId === null || $recipient === null
            || !in_array($recipient['channel'], self::CHANNELS, true)) {
            return; // gone (unsubscribed or stopped) or not a channel of this module
        }
        $contactId = $subscription->contactId;
        $this->state->ensure(self::TYPE, $contactId);
        $db = $this->resource->getConnection();
        $claimed = $db->update($this->state->getTableName(),
            ['sent_at' => new Expression('NOW()'), 'delivery_version' => new Expression('delivery_version + 1')],
            array_merge($this->state->keyCondition(self::TYPE, $contactId), ['sent_at IS NULL']));
        if (!$claimed) {
            return; // already confirmed (or another worker is sending it)
        }
        $storeId = $subscription->storeId;
        $store = $this->config->getStoreName($storeId);
        $text = strtr($this->config->getOptinText($storeId), ['{{store}}' => $store]);
        try {
            $this->sender->send(Delivery::text($storeId, $recipient['channel'], $recipient['recipient'],
                fn () => new DeliveryContent(['store' => $store], text: $text), false, null, false, self::TYPE));
        } catch (MessageSentException $sent) {
            throw $sent; // it is out: keep the claim
        } catch (\Throwable $error) {
            // not sent: release the claim, so a later sign-up of the number may confirm it
            $db->update($this->state->getTableName(), ['sent_at' => null], $this->state->keyCondition(self::TYPE, $contactId));
            throw $error;
        }
    }
}
