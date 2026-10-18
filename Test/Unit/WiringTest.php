<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The module plugs into Notification through its stable extension points only (docs/module-authors.md): every class of
 * Notification and module-rate-limit it names - in PHP and in di.xml - is @api, and it is wired as the Messaging guide
 * says: one provider per channel, quiet hours zones for its channels, the opt-in type, and a rate limit for the
 * delivery reports only.
 */
class WiringTest extends TestCase
{
    private static function di(): \SimpleXMLElement
    {
        return simplexml_load_file(__DIR__ . '/../../etc/di.xml');
    }

    /** @return array<string, string> item name => value of one di.xml array argument */
    private static function items(string $type, string $argument): array
    {
        $items = [];
        foreach (self::di()->xpath(sprintf('//type[@name="%s"]/arguments/argument[@name="%s"]/item', $type, $argument)) as $item) {
            $items[(string)$item['name']] = trim((string)$item);
        }
        return $items;
    }

    public function testOneProviderPerChannelWithItsCodeAndChannel(): void
    {
        $providers = self::items('CommerceKings\Notification\Model\ProviderPool', 'providers');
        $this->assertSame(['infobip_sms', 'infobip_whatsapp', 'infobip_viber'], array_keys($providers));
        foreach (['sms' => 'SMS', 'whatsapp' => 'WHATSAPP', 'viber' => 'VIBER'] as $channel => $constant) {
            $virtual = self::di()->xpath(sprintf('//virtualType[@name="%s"]', $providers['infobip_' . $channel]))[0];
            $this->assertSame('CommerceKings\NotificationInfobip\Model\Provider\InfobipProvider', (string)$virtual['type']);
            $this->assertSame('infobip_' . $channel, (string)$virtual->xpath('arguments/argument[@name="code"]')[0]);
            $this->assertSame('CommerceKings\Notification\Api\Channel::' . $constant,
                (string)$virtual->xpath('arguments/argument[@name="channel"]')[0]);
        }
    }

    public function testZonesTheOptinTypeAndARateLimitForTheReportsOnly(): void
    {
        $this->assertSame(array_fill_keys(['sms', 'whatsapp', 'viber'], 'CommerceKings\NotificationInfobip\Model\NumberZones'),
            self::items('CommerceKings\Notification\Model\QuietHours', 'zones'));
        $this->assertSame(['infobip_optin' => 'CommerceKings\NotificationInfobip\Model\Optin\Confirmation'],
            self::items('CommerceKings\Notification\Model\TypePool', 'types'));
        $limits = self::di()->xpath('//type[@name="CommerceKings\RateLimit\Model\EndpointRegistry"]/arguments'
            . '/argument[@name="definitions"]/item');
        $this->assertCount(1, $limits);
        $this->assertSame('CommerceKings\NotificationInfobip\Controller\Webhook\Report',
            (string)$limits[0]->xpath('item[@name="controller"]')[0], 'never the inbound webhook: a 429 could lose a STOP');
    }

    /** @return string[] Notification and module-rate-limit classes this module names */
    private static function foreignClasses(): array
    {
        $root = dirname(__DIR__, 2);
        $sources = [];
        foreach (['Model', 'Controller', 'Block', 'Observer', 'Cron', 'Exception', 'etc'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($files as $file) {
                if ($file->isFile() && preg_match('/\.(php|xml)$/', $file->getFilename())) {
                    $sources[] = file_get_contents($file->getPathname());
                }
            }
        }
        preg_match_all('/CommerceKings\\\\(?:Notification|RateLimit)\\\\[A-Za-z\\\\]+/', implode("\n", $sources), $matches);
        return array_values(array_unique(array_map(fn ($class) => preg_replace('/::[A-Z_]+$/', '', $class), $matches[0])));
    }

    public function testEveryClassOfNotificationItUsesIsApi(): void
    {
        $classes = self::foreignClasses();
        $this->assertContains('CommerceKings\Notification\Api\StopListInterface', $classes);
        foreach ($classes as $class) {
            if (str_starts_with($class, 'CommerceKings\RateLimit') && !class_exists($class)) {
                continue; // module-rate-limit is optional; its registry entry is skipped without it
            }
            $this->assertTrue(class_exists($class) || interface_exists($class), $class . ' exists');
            $this->assertStringContainsString('@api', (string)(new \ReflectionClass($class))->getDocComment(),
                $class . ' is a stable extension point');
        }
    }
}
