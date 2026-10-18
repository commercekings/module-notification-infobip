<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Model\Webhook;

use CommerceKings\Notification\Api\Channel;

/** An address in an Infobip webhook as Notification takes it: E.164, "+" added (Infobip sends digits only). */
class Address
{
    /** The webhook channels and how a webhook names them (?channel=…, or Infobip's integrationType). */
    public const CHANNELS = [Channel::SMS, Channel::WHATSAPP, Channel::VIBER];

    public static function number(string $address): string
    {
        $number = trim((string)preg_replace('/^(whatsapp|viber|tel):/i', '', trim($address)));
        return $number !== '' && ctype_digit($number) ? '+' . $number : $number;
    }

    /** The channel of a webhook result: the URL's (each webhook URL names one), else Infobip's integration type. */
    public static function channel(?string $hint, array $result): ?string
    {
        $hint = strtolower(trim((string)$hint));
        if (in_array($hint, self::CHANNELS, true)) {
            return $hint;
        }
        $type = strtolower(trim((string)($result['integrationType'] ?? $result['channel'] ?? '')));
        return in_array($type, self::CHANNELS, true) ? $type : null;
    }
}
