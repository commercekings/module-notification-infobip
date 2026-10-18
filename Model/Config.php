<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Model;

use CommerceKings\Notification\Api\Channel;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/** Admin: Stores > Configuration > CommerceKings > Notifications > Infobip (one account per website). */
class Config
{
    private const PREFIX = 'commercekings_notification/infobip/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Json $json,
        private readonly StoreManagerInterface $stores
    ) {
    }

    /** The account's API base URL (Infobip: https://<your-id>.api.infobip.com), without a trailing slash. */
    public function getBaseUrl(int $storeId): string
    {
        $url = rtrim($this->value('base_url', $storeId), '/');
        return $url !== '' && !preg_match('#^https?://#i', $url) ? 'https://' . $url : $url;
    }

    /** Stored encrypted; Magento decrypts it on read (config.xml marks it with the Encrypted backend model). */
    public function getApiKey(int $storeId): string
    {
        return $this->value('api_key', $storeId);
    }

    /** The sender on a channel: an SMS sender ID or number, the WhatsApp number, the Viber sender name. */
    public function getSender(int $storeId, string $channel): string
    {
        return match ($channel) {
            Channel::SMS => $this->value('sms_sender', $storeId),
            Channel::WHATSAPP => $this->value('whatsapp_sender', $storeId),
            Channel::VIBER => $this->value('viber_sender', $storeId),
            default => '',
        };
    }

    /** Authenticates the webhooks (WebhookAuth); stored encrypted. Empty: the webhooks refuse every request. */
    public function getWebhookSecret(int $storeId): string
    {
        return $this->value('webhook_secret', $storeId);
    }

    /** The public URL Infobip calls the webhooks on, without a trailing slash: the setting, else the secure base URL. */
    public function getWebhookBaseUrl(int $storeId): string
    {
        $configured = rtrim($this->value('webhook_base_url', $storeId), '/');
        return $configured !== '' ? $configured
            : rtrim((string)$this->scopeConfig->getValue('web/secure/base_url', ScopeInterface::SCOPE_STORE, $storeId), '/');
    }

    public function isOptinEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(self::PREFIX . 'optin_enabled', ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function getOptinText(int $storeId): string
    {
        return $this->value('optin_text', $storeId);
    }

    /** @return array<int, array<string, mixed>> rows of type, locale, template, language and variables */
    public function getTemplateMap(int $storeId): array
    {
        $raw = $this->value('template_map', $storeId);
        if ($raw === '') {
            return [];
        }
        try {
            $rows = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException) {
            return [];
        }
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * One store view per website (its default), for what is per account: the webhooks name no store, so each website's
     * account is tried.
     *
     * @return int[]
     */
    public function getAccountStores(): array
    {
        $stores = [];
        foreach ($this->stores->getWebsites() as $website) {
            $storeId = (int)$this->stores->getGroup((string)$website->getDefaultGroupId())->getDefaultStoreId();
            if ($storeId) {
                $stores[] = $storeId;
            }
        }
        return $stores;
    }

    private function value(string $field, int $storeId): string
    {
        return trim((string)$this->scopeConfig->getValue(self::PREFIX . $field, ScopeInterface::SCOPE_STORE, $storeId));
    }
}
