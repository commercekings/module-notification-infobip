<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Model\Webhook;

use CommerceKings\NotificationInfobip\Model\Config;

/**
 * Infobip doesn't sign the per-message delivery reports or Forward-to-HTTP messages we use; its webhook subscriptions
 * can, but we don't use them. So the URL carries the proof: a token, an HMAC of the account's webhook secret for that
 * webhook ("inbound" or "report"). The secret itself never travels; a token for one webhook does not open the other,
 * and changing the secret retires the old URLs. A request is the account's whose token matches, or nobody's.
 */
class WebhookAuth
{
    public const INBOUND = 'inbound';
    public const REPORT = 'report';

    public function __construct(private readonly Config $config)
    {
    }

    /** The token of this account's webhook; '' while the account has no webhook secret. */
    public function token(int $storeId, string $webhook): string
    {
        $secret = $this->config->getWebhookSecret($storeId);
        return $secret === '' ? '' : substr(hash_hmac('sha256', 'commercekings_infobip_webhook|' . $webhook, $secret), 0, 40);
    }

    /** The URL Infobip calls, e.g. for the delivery reports of SMS: …/ckinfobip/webhook/report?channel=sms&token=… */
    public function url(int $storeId, string $webhook, string $channel): string
    {
        $token = $this->token($storeId, $webhook);
        if ($token === '') {
            return '';
        }
        return $this->config->getWebhookBaseUrl($storeId) . '/ckinfobip/webhook/' . $webhook . '?'
            . http_build_query(['channel' => $channel, 'token' => $token]);
    }

    /** The store view of the account the token belongs to, or null: refuse the request. */
    public function authenticate(string $token, string $webhook): ?int
    {
        if ($token === '') {
            return null;
        }
        foreach ($this->config->getAccountStores() as $storeId) {
            $expected = $this->token($storeId, $webhook);
            if ($expected !== '' && hash_equals($expected, $token)) {
                return $storeId;
            }
        }
        return null;
    }
}
