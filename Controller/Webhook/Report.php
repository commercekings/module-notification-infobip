<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Controller\Webhook;

use CommerceKings\NotificationInfobip\Model\Webhook\ReportProcessor;
use CommerceKings\NotificationInfobip\Model\Webhook\WebhookAuth;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;

/** Delivery reports of the messages this module sent (their notifyUrl): undeliverable and opted-out numbers. */
class Report extends AbstractWebhook
{
    public function __construct(
        RequestInterface $request,
        RawFactory $rawFactory,
        WebhookAuth $auth,
        private readonly ReportProcessor $processor
    ) {
        parent::__construct($request, $rawFactory, $auth);
    }

    protected function webhook(): string
    {
        return WebhookAuth::REPORT;
    }

    protected function handle(array $payload, ?string $channel): void
    {
        $this->processor->process($payload, $channel);
    }
}
