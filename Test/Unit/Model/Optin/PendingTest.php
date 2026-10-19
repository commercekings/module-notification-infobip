<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Test\Unit\Model\Optin;

use CommerceKings\Notification\Api\DeliveryStateInterface;
use CommerceKings\NotificationInfobip\Model\Optin\Pending;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

/** The pending row of a confirmation: queued, waiting after a failure, due again, given up. */
class PendingTest extends TestCase
{
    private const DUE = '((queued_at IS NULL AND (skip_until IS NULL OR skip_until <= NOW()))'
        . ' OR queued_at < NOW() - INTERVAL 60 MINUTE)';

    private array $updates = [];
    private array $deletes = [];
    private array $ensured = [];
    private array $wheres = [];
    private int $failedSends = 0;
    private int $affected = 1;

    private function pending(): Pending
    {
        $state = $this->createStub(DeliveryStateInterface::class);
        $state->method('getTableName')->willReturn('ck_notification_delivery_state');
        $state->method('keyCondition')->willReturnCallback(fn ($type, $id) => ['type = ?' => $type, 'reference_id = ?' => $id, "subject_key = ''"]);
        $state->method('ensure')->willReturnCallback(function (...$key) { $this->ensured[] = $key; });
        $state->method('load')->willReturnCallback(fn () => ['failed_sends' => (string)$this->failedSends]);
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function (...$where) use ($select) {
            $this->wheres[] = array_values(array_filter($where, fn ($value) => $value !== null)); // stubs fill the defaults
            return $select;
        });
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();
        $db = $this->createStub(AdapterInterface::class);
        $db->method('select')->willReturn($select);
        $db->method('fetchCol')->willReturn(['7', '9']);
        $db->method('fetchOne')->willReturn('1');
        $db->method('update')->willReturnCallback(function ($table, $data, $where) {
            $this->updates[] = [array_map(fn ($value) => is_object($value) ? (string)$value : $value, $data), $where];
            return $this->affected;
        });
        $db->method('delete')->willReturnCallback(function ($table, $where) {
            $this->deletes[] = $where;
            return 1;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        return new Pending($resource, $state);
    }

    private function key(int $id): array
    {
        return ['type = ?' => 'infobip_optin_due', 'reference_id = ?' => $id, "subject_key = ''"];
    }

    public function testQueuedThenNotQueuedWaitsAFewMinutesWithoutCountingAnAttempt(): void
    {
        $pending = $this->pending();
        $pending->queued(7);
        $pending->notQueued(7);
        $this->assertSame([['infobip_optin_due', 7, ''], ['infobip_optin_due', 7, '']], $this->ensured);
        $this->assertSame([
            [['queued_at' => 'NOW()', 'skip_until' => null], $this->key(7)],
            [['queued_at' => null, 'skip_until' => 'NOW() + INTERVAL 300 SECOND'], $this->key(7)],
        ], $this->updates);
    }

    public function testEachFailureWaitsLongerUpToAnHourThenGivesUp(): void
    {
        $waits = [];
        foreach ([0, 1, 3, 4, 6] as $earlier) {
            $this->failedSends = $earlier;
            $this->updates = [];
            $this->assertTrue($this->pending()->failed(7));
            $waits[] = [$this->updates[0][0]['failed_sends'], $this->updates[0][0]['skip_until']];
        }
        $this->assertSame([[1, 'NOW() + INTERVAL 300 SECOND'], [2, 'NOW() + INTERVAL 600 SECOND'],
            [4, 'NOW() + INTERVAL 2400 SECOND'], [5, 'NOW() + INTERVAL 3600 SECOND'],
            [7, 'NOW() + INTERVAL 3600 SECOND']], $waits);
        $this->failedSends = 7;
        $this->updates = [];
        $this->assertFalse($this->pending()->failed(7), 'the eighth failure gives up');
        $this->assertSame([], $this->updates);
        $this->assertSame([$this->key(7)], $this->deletes, 'the row goes');
    }

    public function testTheProvidersRetryAfterIsKeptWithinBounds(): void
    {
        foreach ([[120, 120], [5, 60], [999999, 86400]] as [$asked, $kept]) {
            $this->updates = [];
            $this->pending()->failed(7, $asked);
            $this->assertSame('NOW() + INTERVAL ' . $kept . ' SECOND', $this->updates[0][0]['skip_until']);
            $this->assertNull($this->updates[0][0]['queued_at']);
        }
    }

    public function testDueAreWaitsThatEndedAndLostMessagesAndOnlyOneRunClaimsEach(): void
    {
        $pending = $this->pending();
        $this->assertSame([7, 9], $pending->due());
        $this->assertSame([['type = ?', 'infobip_optin_due'], [self::DUE]], $this->wheres);
        $this->assertTrue($pending->claim(7));
        $this->assertSame([['queued_at' => 'NOW()'], $this->key(7) + [1 => self::DUE]], $this->updates[0]);
        $this->affected = 0;
        $this->assertFalse($pending->claim(7), 'another run won it');
    }

    public function testAWaitingRetryIsSeenByADuplicate(): void
    {
        $this->assertTrue($this->pending()->isWaiting(7));
        $this->assertSame([['skip_until > NOW()'], ['type = ?', 'infobip_optin_due'], ['reference_id = ?', 7],
            ["subject_key = ''"]], $this->wheres);
    }

    public function testAnInterruptedSendIsClosedNotResent(): void
    {
        $this->assertSame(1, $this->pending()->interrupted());
        $this->assertSame([['queued_at' => null], ['type = ?' => 'infobip_optin', 'sent_at IS NOT NULL',
            'queued_at < NOW() - INTERVAL 60 MINUTE']], $this->updates[0], 'sent_at stays: it may be out');
    }
}
