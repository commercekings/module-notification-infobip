<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Model;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\StopListInterface;
use CommerceKings\NotificationInfobip\Exception\InfobipException;
use CommerceKings\NotificationInfobip\Model\Http\InfobipClient;
use CommerceKings\NotificationInfobip\Model\Webhook\WebhookAuth;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * One Infobip message for a delivery, on the delivery's channel only: an SMS or a Viber message with the text, a
 * WhatsApp template (by notification type and locale) with its variables. Infobip's channel failover is never asked
 * for - a message falling back to another channel would skip that channel's stop list, consent and quiet hours. Each
 * message asks for the delivery report webhook, which reports late failures. A refusal because the person blocked or
 * opted out of this sender is their opt-out: the stop list learns it, then the send fails as refused.
 */
class MessageSender
{
    /** Infobip's send endpoints per channel. */
    public const PATHS = [
        Channel::SMS => '/sms/2/text/advanced',
        Channel::WHATSAPP => '/whatsapp/1/message/template',
        Channel::VIBER => '/viber/2/messages',
    ];

    /** Parts of Infobip status or error names that mean the person blocked or opted out of the sender. */
    public const OPTED_OUT = ['BLOCK', 'OPT_OUT', 'OPTOUT', 'OPTED_OUT', 'UNSUBSCRIB'];

    public function __construct(
        private readonly Config $config,
        private readonly InfobipClient $client,
        private readonly TemplateMap $templates,
        private readonly WebhookAuth $webhooks,
        private readonly StopListInterface $stopList,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /** Is this Infobip status or error name the person's opt-out (blocked the sender, unsubscribed)? */
    public static function isOptOut(string $name): bool
    {
        $name = strtoupper($name);
        foreach (self::OPTED_OUT as $part) {
            if ($name !== '' && str_contains($name, $part)) {
                return true;
            }
        }
        return false;
    }

    public function send(Delivery $delivery, DeliveryContent $content): void
    {
        $storeId = $delivery->storeId;
        $channel = $delivery->channel;
        if (!isset(self::PATHS[$channel])) {
            throw new \InvalidArgumentException(sprintf('Infobip does not send on the "%s" channel.', $channel));
        }
        $sender = $this->config->getSender($storeId, $channel);
        if ($sender === '') {
            throw new \RuntimeException(sprintf('Infobip has no %s sender for this store.', $channel));
        }
        // Infobip takes international numbers without "+"; Notification's are E.164
        $to = ltrim($delivery->recipient, '+');
        $report = $this->webhooks->url($storeId, WebhookAuth::REPORT, $channel);
        $message = match ($channel) {
            Channel::SMS => ['destinations' => [['to' => $to]], 'from' => $sender, 'text' => $this->text($content)]
                + ($report !== '' ? ['notifyUrl' => $report, 'notifyContentType' => 'application/json'] : []),
            Channel::WHATSAPP => ['from' => ltrim($sender, '+'), 'to' => $to, 'content' => $this->template($delivery, $content)]
                + ($report !== '' ? ['notifyUrl' => $report] : []),
            Channel::VIBER => ['sender' => $sender, 'destinations' => [['to' => $to]],
                'content' => ['text' => $this->text($content), 'type' => 'TEXT']]
                + ($report !== '' ? ['webhooks' => ['delivery' => ['url' => $report]]] : []),
        };
        try {
            $this->client->send($this->config->getBaseUrl($storeId), $this->config->getApiKey($storeId),
                self::PATHS[$channel], ['messages' => [$message]]);
        } catch (InfobipException $error) {
            if (self::isOptOut($error->getInfobipName())) {
                $this->stopList->stop($channel, $delivery->recipient, StopListInterface::REASON_REPLY);
            }
            throw $error;
        }
    }

    private function text(DeliveryContent $content): string
    {
        $text = trim((string)$content->text);
        if ($text === '') {
            throw new \RuntimeException('An SMS or Viber message needs a text (DeliveryContent::$text).');
        }
        return $text;
    }

    /**
     * WhatsApp outside a conversation takes approved templates only: the type's template for the store's locale (read
     * inside the store's environment), else Delivery::$templateId with the locale's language.
     *
     * @return array<string, mixed>
     */
    private function template(Delivery $delivery, DeliveryContent $content): array
    {
        $locale = (string)$this->scopeConfig->getValue('general/locale/code', ScopeInterface::SCOPE_STORE, $delivery->storeId);
        $template = $this->templates->find($delivery->storeId, $delivery->type, $locale)
            ?? ($delivery->templateId !== '' ? ['template' => $delivery->templateId,
                'language' => strtolower(explode('_', $locale)[0]), 'variables' => []] : null);
        if ($template === null) {
            throw new \RuntimeException(sprintf('No WhatsApp template for "%s" (%s): add one under Infobip > WhatsApp'
                . ' templates.', $delivery->type, $locale));
        }
        $placeholders = [];
        foreach ($template['variables'] as $name) {
            $value = $content->vars[$name] ?? '';
            $placeholders[] = is_scalar($value) ? (string)$value : '';
        }
        return ['templateName' => $template['template'], 'templateData' => ['body' => ['placeholders' => $placeholders]],
            'language' => $template['language']];
    }
}
