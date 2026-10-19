<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Controller\Webhook;

use CommerceKings\NotificationInfobip\Model\Webhook\WebhookAuth;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * An Infobip webhook: POST with a JSON body, authorized by its URL's token (WebhookAuth - Infobip doesn't sign these
 * webhooks, and a form key cannot apply), 403 without doing anything when it does not match an account. An authorized request is
 * answered 200, also when a result in it could not be used: a retry would not change that.
 */
abstract class AbstractWebhook implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        protected readonly RequestInterface $request,
        protected readonly RawFactory $rawFactory,
        protected readonly WebhookAuth $auth
    ) {
    }

    /** WebhookAuth::INBOUND or WebhookAuth::REPORT. */
    abstract protected function webhook(): string;

    /** @param array<string, mixed> $payload */
    abstract protected function handle(array $payload, ?string $channel): void;

    public function execute(): ResultInterface
    {
        if ($this->auth->authenticate((string)$this->request->getParam('token', ''), $this->webhook()) === null) {
            return $this->answer(403, 'Not authorized.');
        }
        $body = method_exists($this->request, 'getContent') ? (string)$this->request->getContent() : '';
        $payload = json_decode($body, true);
        $channel = $this->request->getParam('channel');
        $this->handle(is_array($payload) ? $payload : [], is_string($channel) ? $channel : null);
        return $this->answer(200, '');
    }

    private function answer(int $status, string $text): Raw
    {
        return $this->rawFactory->create()->setHttpResponseCode($status)
            ->setHeader('Content-Type', 'text/plain; charset=UTF-8', true)->setContents($text);
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true; // the URL's token authorizes it (checked in execute())
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }
}
