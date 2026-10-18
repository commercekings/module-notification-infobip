<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Model\Provider;

use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\ProviderInterface;
use CommerceKings\NotificationInfobip\Model\MessageSender;

/**
 * Infobip on one of Notification's channels - a virtual type per channel in etc/di.xml (infobip_sms, infobip_whatsapp,
 * infobip_viber) with its code, label and channel.
 */
class InfobipProvider implements ProviderInterface
{
    public function __construct(
        private readonly MessageSender $sender,
        private readonly string $code,
        private readonly string $label,
        private readonly string $channel
    ) {
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function supportsOneClickUnsubscribe(): bool
    {
        return false;
    }

    public function send(Delivery $delivery, DeliveryContent $content): void
    {
        $this->sender->send($delivery, $content);
    }
}
