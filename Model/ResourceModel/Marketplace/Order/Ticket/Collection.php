<?php

namespace ShoppingFeed\Manager\Model\ResourceModel\Marketplace\Order\Ticket;

use ShoppingFeed\Manager\Api\Data\Marketplace\Order\TicketInterface;
use ShoppingFeed\Manager\Model\Marketplace\Order\Ticket;
use ShoppingFeed\Manager\Model\ResourceModel\AbstractCollection;
use ShoppingFeed\Manager\Model\ResourceModel\Marketplace\Order\Ticket as TicketResource;

/**
 * @method TicketResource getResource()
 */
class Collection extends AbstractCollection
{
    protected $_idFieldName = TicketInterface::TICKET_ID;

    protected function _construct()
    {
        $this->_init(Ticket::class, TicketResource::class);
    }

    private function joinOrderTable()
    {
        if (!$this->hasFlag('_order_table_joined_')) {
            $this->join(
                [ 'order_table' => $this->tableDictionary->getMarketplaceOrderTableName() ],
                'main_table.order_id = order_table.order_id',
                []
            );

            $this->setFlag('_order_table_joined_', true);

            $this->addFilterToMap(TicketInterface::ORDER_ID, 'main_table.' . TicketInterface::ORDER_ID);
            $this->addFilterToMap(TicketInterface::STATUS, 'main_table.' . TicketInterface::STATUS);
            $this->addFilterToMap(TicketInterface::CREATED_AT, 'main_table.' . TicketInterface::CREATED_AT);
        }

        return $this;
    }

    /**
     * @param int|int[] $ids
     * @return $this
     */
    public function addStoreIdFilter($ids)
    {
        $this->joinOrderTable();

        $this->addFieldToFilter('order_table.store_id', [ 'in' => $this->prepareIdFilterValue($ids) ]);

        return $this;
    }

    /**
     * @param int|int[] $ids
     * @return $this
     */
    public function addIdFilter($ids)
    {
        $this->addFieldToFilter(TicketInterface::TICKET_ID, [ 'in' => $this->prepareIdFilterValue($ids) ]);

        return $this;
    }

    /**
     * @param int|int[] $ids
     * @return $this
     */
    public function addExcludedIdFilter($ids)
    {
        if (is_array($ids) && empty($ids)) {
            return $this;
        }

        $this->addFieldToFilter(TicketInterface::TICKET_ID, [ 'nin' => $this->prepareIdFilterValue($ids) ]);

        return $this;
    }

    /**
     * @param string $action
     * @return $this
     */
    public function addActionFilter($action)
    {
        $this->addFieldToFilter(TicketInterface::ACTION, (string) $action);

        return $this;
    }

    /**
     * @param int|int[] $orderIds
     * @return $this
     */
    public function addOrderIdFilter($orderIds)
    {
        $this->addFieldToFilter(TicketInterface::ORDER_ID, [ 'in' => $this->prepareIdFilterValue($orderIds) ]);

        return $this;
    }

    /**
     * @param int $status
     * @return $this
     */
    public function addStatusFilter($status)
    {
        $this->addFieldToFilter(TicketInterface::STATUS, (int) $status);

        return $this;
    }

    /**
     * @param int $days
     * @return $this
     */
    public function addMaxAgeFilter($days)
    {
        $this->addFieldToFilter(
            TicketInterface::CREATED_AT,
            [ 'gteq' => new \Zend_Db_Expr(sprintf('DATE_SUB(NOW(), INTERVAL %d DAY)', (int) $days)) ]
        );

        return $this;
    }

    /**
     * @return TicketInterface[][]
     */
    public function getTicketsByOrder()
    {
        return $this->getGroupedItems([ TicketInterface::ORDER_ID ], true);
    }
}
