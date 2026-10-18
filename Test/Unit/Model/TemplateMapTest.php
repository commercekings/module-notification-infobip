<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Test\Unit\Model;

use CommerceKings\NotificationInfobip\Model\Config;
use CommerceKings\NotificationInfobip\Model\TemplateMap;
use PHPUnit\Framework\TestCase;

class TemplateMapTest extends TestCase
{
    private function map(array $rows): TemplateMap
    {
        $config = $this->createStub(Config::class);
        $config->method('getTemplateMap')->willReturn($rows);
        return new TemplateMap($config);
    }

    public function testTheLocaleThenItsLanguageThenAnyLocale(): void
    {
        $map = $this->map([
            ['type' => 'cart_reminder_whatsapp', 'locale' => '', 'template' => 'cart_any', 'variables' => 'name'],
            ['type' => 'cart_reminder_whatsapp', 'locale' => 'de', 'template' => 'cart_de', 'language' => 'de'],
            ['type' => 'cart_reminder_whatsapp', 'locale' => 'de_CH', 'template' => 'cart_ch', 'language' => 'de',
                'variables' => ' name , link,, '],
            ['type' => 'other', 'locale' => 'de_DE', 'template' => 'x'],
        ]);
        $this->assertSame(['template' => 'cart_ch', 'language' => 'de', 'variables' => ['name', 'link']],
            $map->find(1, 'cart_reminder_whatsapp', 'de_CH'));
        $this->assertSame('cart_de', $map->find(1, 'cart_reminder_whatsapp', 'de_AT')['template']);
        $this->assertSame(['template' => 'cart_any', 'language' => 'fr', 'variables' => ['name']],
            $map->find(1, 'cart_reminder_whatsapp', 'fr_FR'), 'no language set: the locale\'s');
        $this->assertNull($map->find(1, 'cart_reminder_sms', 'de_DE'));
        $this->assertNull($map->find(1, '', 'de_DE'), 'an unknown type has no template');
    }

    public function testARowWithoutATemplateNameIsSkipped(): void
    {
        $this->assertNull($this->map([['type' => 't', 'locale' => '', 'template' => ' ']])->find(1, 't', 'en_US'));
    }
}
