<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Test\Unit\Model;

use CommerceKings\NotificationInfobip\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private function config(?string $storeInformationName): Config
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(fn ($path, $type = null, $store = null) =>
            $path === 'general/store_information/name' && $type === ScopeInterface::SCOPE_STORE && $store === 3
                ? $storeInformationName : null);
        $store = $this->createStub(Store::class);
        $store->method('getFrontendName')->willReturn('Main Website Store');
        $store->method('getName')->willReturn('Default Store View');
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);
        return new Config($scope, new Json(), $stores);
    }

    public function testTheBrandIsTheStoreViewsStoreInformationNameElseItsFrontendName(): void
    {
        $this->assertSame('Acme', $this->config(' Acme ')->getStoreName(3));
        $this->assertSame('Main Website Store', $this->config('')->getStoreName(3), 'not the store view name');
        $this->assertSame('Main Website Store', $this->config(null)->getStoreName(3));
    }
}
