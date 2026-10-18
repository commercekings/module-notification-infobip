<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Controller\Webhook;

use CommerceKings\NotificationInfobip\Model\Webhook\InboundProcessor;
use CommerceKings\NotificationInfobip\Model\Webhook\WebhookAuth;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;

/** Messages to the shop's senders (Infobip: "Forward to HTTP"): STOP / START keep Notification's stop list in step. */
class Inbound extends AbstractWebhook
{
    public function __construct(
        RequestInterface $request,
        RawFactory $rawFactory,
        WebhookAuth $auth,
        private readonly InboundProcessor $processor
    ) {
        parent::__construct($request, $rawFactory, $auth);
    }

    protected function webhook(): string
    {
        return WebhookAuth::INBOUND;
    }

    protected function handle(array $payload, ?string $channel): void
    {
        $this->processor->process($payload, $channel);
    }
}
