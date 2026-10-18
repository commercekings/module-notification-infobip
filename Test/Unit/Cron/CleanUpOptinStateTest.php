<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Test\Unit\Cron;

use CommerceKings\Notification\Api\DeliveryStateInterface;
use CommerceKings\NotificationInfobip\Cron\CleanUpOptinState;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\TestCase;

class CleanUpOptinStateTest extends TestCase
{
    public function testRemovesTheClaimsOfNumbersNotificationNoLongerKeeps(): void
    {
        $queries = [];
        $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
        $statement->method('rowCount')->willReturn(3);
        $db = $this->createStub(AdapterInterface::class);
        $db->method('quoteIdentifier')->willReturnArgument(0);
        $db->method('query')->willReturnCallback(function ($sql, $bind) use (&$queries, $statement) {
            $queries[] = [$sql, $bind];
            return $statement;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        $state = $this->createStub(DeliveryStateInterface::class);
        $state->method('getTableName')->willReturn('ck_notification_delivery_state');
        $this->assertSame(3, (new CleanUpOptinState($resource, $state))->execute());
        $this->assertSame([['DELETE d FROM ck_notification_delivery_state AS d WHERE d.type = ? AND NOT EXISTS'
            . ' (SELECT 1 FROM ck_notification_contact AS n WHERE n.contact_id = d.reference_id)', ['infobip_optin']]], $queries);
    }
}
