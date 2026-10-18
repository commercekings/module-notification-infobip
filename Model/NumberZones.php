<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Model;

use CommerceKings\Notification\Api\QuietHoursZoneInterface;
use libphonenumber\PhoneNumberToTimeZonesMapper;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Quiet hours in the recipient's time zone (registered for sms, whatsapp and viber in Model\QuietHours "zones"): the
 * zones libphonenumber knows for the number's country and area code - several for a number whose area spans zones,
 * and Notification keeps it quiet while any of them is in quiet hours. A number it cannot place keeps the store's
 * zone (never "not quiet"); the whole store (null) gets [] - each delivery is then held on its own.
 */
class NumberZones implements QuietHoursZoneInterface
{
    public function __construct(private readonly TimezoneInterface $timezone)
    {
    }

    public function getZones(int $storeId, string $channel, ?string $recipient): array
    {
        if ($recipient === null || $recipient === '') {
            return [];
        }
        $zones = [];
        try {
            $number = PhoneNumberUtil::getInstance()->parse($recipient, null);
            foreach (PhoneNumberToTimeZonesMapper::getInstance()->getTimeZonesForNumber($number) as $name) {
                if ($name === PhoneNumberToTimeZonesMapper::UNKNOWN_TIMEZONE) {
                    continue;
                }
                try {
                    $zones[] = new \DateTimeZone($name);
                } catch (\Exception) {
                    continue; // a zone this PHP does not know
                }
            }
        } catch (NumberParseException) {
            $zones = [];
        }
        return $zones ?: [$this->storeZone($storeId)];
    }

    private function storeZone(int $storeId): \DateTimeZone
    {
        $zone = (string)$this->timezone->getConfigTimezone(ScopeInterface::SCOPE_STORE, (string)$storeId);
        try {
            return new \DateTimeZone($zone !== '' ? $zone : 'UTC');
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }
}
