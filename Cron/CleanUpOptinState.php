<?php
declare(strict_types=1);

namespace CommerceKings\NotificationInfobip\Cron;

use CommerceKings\Notification\Api\ContactRepositoryInterface;
use CommerceKings\Notification\Api\DeliveryStateInterface;
use CommerceKings\NotificationInfobip\Model\Optin\Confirmation;
use Magento\Framework\App\ResourceConnection;

/**
 * Daily: removes the opt-in confirmation's "sent" rows of numbers Notification no longer keeps (the contact went with
 * its last subscription). Notification's own delivery state cleanup covers subscription types only.
 */
class CleanUpOptinState
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly DeliveryStateInterface $state
    ) {
    }

    public function execute(): int
    {
        $db = $this->resource->getConnection();
        return $db->query(sprintf('DELETE d FROM %s AS d WHERE d.type = ? AND NOT EXISTS'
            . ' (SELECT 1 FROM %s AS n WHERE n.contact_id = d.reference_id)',
            $db->quoteIdentifier($this->state->getTableName()),
            $db->quoteIdentifier($this->resource->getTableName(ContactRepositoryInterface::TABLE))),
            [Confirmation::TYPE])->rowCount();
    }
}
