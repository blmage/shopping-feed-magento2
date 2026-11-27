<?php

namespace ShoppingFeed\Manager\Model\ResourceModel\Marketplace;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use ShoppingFeed\Manager\Api\Data\Marketplace\OrderInterface;
use ShoppingFeed\Manager\Model\ResourceModel\AbstractDb;

class Order extends AbstractDb
{
    const DATA_OBJECT_FIELD_NAMES = [ OrderInterface::ADDITIONAL_FIELDS ];

    protected function _construct()
    {
        $this->_init('sfm_marketplace_order', OrderInterface::ORDER_ID);
    }

    /**
     * @param AbstractModel $object
     * @return $this|void
     * @throws LocalizedException
     */
    protected function _afterSave(AbstractModel $object)
    {
        /** @var OrderInterface $object */
        parent::_afterSave($object);
        $connection = $this->getConnection();
        $objectSalesOrderId = $object->getSalesOrderId();

        $actualSalesOrderId = $connection->fetchOne(
            $connection->select()
                ->from($this->getMainTable(), [ OrderInterface::SALES_ORDER_ID ])
                ->where('order_id = ?', $object->getId())
        );

        if (empty($actualSalesOrderId)) {
            $actualSalesOrderId = null;
        } else {
            $actualSalesOrderId = (int) $actualSalesOrderId;
        }

        if ($objectSalesOrderId !== $actualSalesOrderId) {
            throw new LocalizedException(__('A marketplace order can only be imported once.'));
        }
    }

    protected function prepareDataForUpdate($object)
    {
        $data = parent::prepareDataForUpdate($object);

        // The fetch and the synchronization can run at the same time, and both update orders: the syncing flags are
        // only written by their own targeted updates, never with the rest of a (possibly outdated) order.
        unset(
            $data[OrderInterface::SYNCING_PENDING_CHANGES],
            $data[OrderInterface::SYNCING_CHANGE_COUNT],
            $data[OrderInterface::IS_HELD_BY_SYNCING]
        );

        if (isset($data[OrderInterface::SALES_ORDER_ID])) {
            // Prevent importing marketplace orders twice by only updating the `sales_order_id` field when it is empty,
            // or when it has the same value as the one that we are saving.
            // If the check in _afterSave() detects a discrepancy, an exception will be thrown.
            $connection = $this->getConnection();
            $salesOrderId = $data[OrderInterface::SALES_ORDER_ID];

            $data[OrderInterface::SALES_ORDER_ID] = $connection->getCheckSql(
                $connection->prepareSqlCondition(
                    OrderInterface::SALES_ORDER_ID,
                    [ [ 'null' => true ], [ 'eq' => $salesOrderId ] ]
                ),
                $connection->quote($salesOrderId),
                $connection->quoteIdentifier(OrderInterface::SALES_ORDER_ID)
            );
        }

        return $data;
    }

    /**
     * @param int $orderId
     * @throws LocalizedException
     */
    public function bumpOrderImportTryCount($orderId)
    {
        $connection = $this->getConnection();

        $connection->update(
            $this->getMainTable(),
            [ 'import_remaining_try_count' => new \Zend_Db_Expr('import_remaining_try_count - 1') ],
            $connection->quoteInto('order_id = ?', $orderId)
            . ' AND '
            . $connection->quoteInto('import_remaining_try_count > ?', 0)
        );
    }

    /**
     * Record changes to synchronize, and count them.
     *
     * @param int $orderId
     * @param int $changes Kinds of changes (see the OrderInterface::SYNCING_CHANGE_* constants).
     * @throws LocalizedException
     */
    public function addOrderSyncingPendingChanges($orderId, $changes)
    {
        $changes = (int) $changes;

        if ($changes <= 0) {
            return;
        }

        $connection = $this->getConnection();
        $pendingField = $connection->quoteIdentifier(OrderInterface::SYNCING_PENDING_CHANGES);
        $countField = $connection->quoteIdentifier(OrderInterface::SYNCING_CHANGE_COUNT);

        $connection->update(
            $this->getMainTable(),
            [
                OrderInterface::SYNCING_PENDING_CHANGES => new \Zend_Db_Expr($pendingField . ' | ' . $changes),
                OrderInterface::SYNCING_CHANGE_COUNT => new \Zend_Db_Expr($countField . ' + 1'),
            ],
            $connection->quoteInto(OrderInterface::ORDER_ID . ' = ?', $orderId)
        );
    }

    /**
     * Clear synchronized changes, unless new changes were recorded in the meantime (the fetch and the synchronization
     * can run at the same time): everything is then left for the next run.
     *
     * @param int $orderId
     * @param int $changes The synchronized kinds of changes.
     * @param int $synchronizedChangeCount The change count when the order was read for the synchronization.
     * @param int $retriedChanges The kinds of changes to synchronize again at the next run (set if not already).
     * @return bool Whether the changes were cleared.
     * @throws LocalizedException
     */
    public function clearOrderSyncingPendingChanges($orderId, $changes, $synchronizedChangeCount, $retriedChanges = 0)
    {
        $changes = (int) $changes;
        $retriedChanges = (int) $retriedChanges;

        if (($changes <= 0) && ($retriedChanges <= 0)) {
            return true;
        }

        $connection = $this->getConnection();
        $pendingField = $connection->quoteIdentifier(OrderInterface::SYNCING_PENDING_CHANGES);
        $keptChangesMask = 0xFFFF & ~$changes;

        return $connection->update(
            $this->getMainTable(),
            [
                OrderInterface::SYNCING_PENDING_CHANGES => new \Zend_Db_Expr(
                    '(' . $pendingField . ' & ' . $keptChangesMask . ') | ' . $retriedChanges
                ),
            ],
            $connection->quoteInto(OrderInterface::ORDER_ID . ' = ?', $orderId)
            . ' AND '
            . $connection->quoteInto(OrderInterface::SYNCING_CHANGE_COUNT . ' = ?', (int) $synchronizedChangeCount)
        ) > 0;
    }

    /**
     * @param int $orderId
     * @param bool $isHeldBySyncing
     * @throws LocalizedException
     */
    public function updateOrderIsHeldBySyncing($orderId, $isHeldBySyncing)
    {
        $connection = $this->getConnection();

        $connection->update(
            $this->getMainTable(),
            [ OrderInterface::IS_HELD_BY_SYNCING => $isHeldBySyncing ? 1 : 0 ],
            $connection->quoteInto(OrderInterface::ORDER_ID . ' = ?', $orderId)
        );
    }

    /**
     * @param int|null $storeId
     * @return string[]
     */
    public function getMarketplaceList($storeId = null)
    {
        $connection = $this->getConnection();

        $marketplaceSelect = $connection->select()
            ->distinct(true)
            ->from($this->getMainTable(), [ OrderInterface::MARKETPLACE_NAME ]);

        if (null !== $storeId) {
            $marketplaceSelect->where(OrderInterface::STORE_ID . ' = ?', (int) $storeId);
        }

        return $connection->fetchCol($marketplaceSelect);
    }

    /**
     * @param int|null $storeId
     * @return array
     */
    public function getChannelMap($storeId = null)
    {
        $connection = $this->getConnection();

        $marketplaceSelect = $connection->select()
            ->distinct(true)
            ->from(
                $this->getMainTable(),
                [
                    OrderInterface::SHOPPING_FEED_MARKETPLACE_ID,
                    OrderInterface::MARKETPLACE_NAME,
                ]
            );

        if (null !== $storeId) {
            $marketplaceSelect->where(OrderInterface::STORE_ID . ' = ?', (int) $storeId);
        }

        return $connection->fetchPairs($marketplaceSelect);
    }
}
