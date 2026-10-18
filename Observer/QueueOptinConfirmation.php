<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Observer;

use CommerceKings\Notification\Api\ProviderPoolInterface;
use CommerceKings\Notification\Api\QueueInterface;
use CommerceKings\NotificationInfobip\Model\Config;
use CommerceKings\NotificationInfobip\Model\Optin\Confirmation;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * commercekings_notification_subscription_saved: the first subscription of a number in the website
 * (first_for_address) gets one opt-in confirmation - queued, never sent from the shopper's request - when Infobip is
 * the channel's selected provider and the store has it on. A new cart of a known number gets none; with another
 * provider selected for the channel, that provider's module confirms (or not).
 */
class QueueOptinConfirmation implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ProviderPoolInterface $providers,
        private readonly QueueInterface $queue
    ) {
    }

    public function execute(Observer $observer): void
    {
        $channel = (string)$observer->getData('channel');
        $storeId = (int)$observer->getData('store_id');
        if (!in_array($channel, Confirmation::CHANNELS, true) || !$observer->getData('first_for_address')
            || !$observer->getData('contact_id') || !$this->config->isOptinEnabled($storeId)) {
            return;
        }
        try {
            $provider = $this->providers->getSelected($storeId, $channel)->getCode();
        } catch (LocalizedException) {
            return;
        }
        if (str_starts_with($provider, 'infobip_')) {
            $this->queue->publish(Confirmation::TYPE, (string)(int)$observer->getData('subscription_id'));
        }
    }
}
