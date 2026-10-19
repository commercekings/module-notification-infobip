<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Model;

use CommerceKings\Notification\Api\StopListInterface;

/**
 * Which Infobip refusals concern the shopper's number, from Infobip's documented status and error names
 * (https://www.infobip.com/docs/essentials/api-essentials/response-status-and-error-codes). Only an explicit opt-out
 * is the person's opt-out (StopListInterface::REASON_REPLY) and only a number-level failure suppresses the number
 * (REASON_UNDELIVERABLE). Everything else - the shop's account, sender, credit, demo whitelist, routes, network or
 * an unknown name - stops nobody: the shop has to fix it, and the send just fails.
 */
class ErrorCodes
{
    /** The person blocked or opted out of the sender (explicit names only, never a substring). */
    public const OPT_OUT = [
        'REJECTED_DND',                // status 10: the user is subscribed to a Do Not Disturb service
        'EC_SC_BLOCKED_BY_END_USER',   // 573: the end user asked their operator to block the sender
        'EC_CONTENT_BLOCKED',          // 603: blocked by the user's opt-out (MO: STOP)
        // 7003: the user blocked the business - documented under RCS; applied to WhatsApp and Viber on the assumption
        // that Infobip uses it there too
        'EC_BLOCKED_USER',
        'EC_TEMPLATE_BLOCKED_BY_USER', // 7030: the user blocked this template (WhatsApp)
        'EC_MARKETING_OPT_OUT',        // 7033: the user turned off marketing messages (WhatsApp)
    ];

    /** The number itself cannot receive on the channel. */
    public const NUMBER = [
        'REJECTED_PREFIX_MISSING',                // status 8: not a number (prefix or length)
        'REJECTED_DESTINATION',                   // status 52: invalid destination
        'EC_UNKNOWN_SUBSCRIBER',                  // 1: unknown to the network
        'EC_TELESERVICE_NOT_PROVISIONED',         // 11: the subscription has no SMS
        'EC_INVALID_REQUEST_DESTINATION',         // 352: the country code is not recognized
        'EC_DESTINATION_NOT_SMS_PROVISIONED',     // 568: not provisioned for SMS
        'EC_DEST_ADDRESS_UNABLE_TO_RECEIVE_SMS',  // 629: cannot receive SMS (e.g. a landline)
        'EC_DEACTIVATED_LIST',                    // 3041: deactivated number
        // 7002: the user is unknown on the channel - documented under RCS; applied to WhatsApp and Viber on the
        // assumption that Infobip uses it there too
        'EC_UNKNOWN_USER',
    ];

    /**
     * The stop reason for a refusal, or null when the number is not to blame.
     *
     * @param string ...$names Infobip's status and error names (case-insensitive; empty ones are ignored)
     */
    public static function reason(string ...$names): ?string
    {
        $names = array_map('strtoupper', array_map('trim', $names));
        if (array_intersect($names, self::OPT_OUT)) {
            return StopListInterface::REASON_REPLY;
        }
        return array_intersect($names, self::NUMBER) ? StopListInterface::REASON_UNDELIVERABLE : null;
    }
}
