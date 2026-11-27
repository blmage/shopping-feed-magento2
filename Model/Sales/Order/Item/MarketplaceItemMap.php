<?php

namespace ShoppingFeed\Manager\Model\Sales\Order\Item;

use Magento\Framework\Phrase;
use Magento\Sales\Model\Order\Item as SalesOrderItem;

/**
 * The top-level items of a sales order, by state of the marketplace item they were imported from.
 */
class MarketplaceItemMap
{
    /**
     * @var SalesOrderItem[][]
     */
    private $orderItemsByState;

    /**
     * @var int[]
     */
    private $marketplaceItemCountsByState;

    /**
     * @var Phrase|null
     */
    private $unclearReason;

    /**
     * @param SalesOrderItem[][] $orderItemsByState Order items by item ID, by state.
     * @param int[] $marketplaceItemCountsByState
     * @param Phrase|null $unclearReason
     */
    public function __construct(
        array $orderItemsByState,
        array $marketplaceItemCountsByState,
        ?Phrase $unclearReason = null
    ) {
        $this->orderItemsByState = $orderItemsByState;
        $this->marketplaceItemCountsByState = $marketplaceItemCountsByState;
        $this->unclearReason = $unclearReason;
    }

    /**
     * Whether each order item can be matched with certainty to one marketplace item, and the other way around.
     * Nothing should be done automatically from an unclear map.
     *
     * @return bool
     */
    public function isClear()
    {
        return null === $this->unclearReason;
    }

    /**
     * @return Phrase|null
     */
    public function getUnclearReason()
    {
        return $this->unclearReason;
    }

    /**
     * @param string $state
     * @return SalesOrderItem[]
     */
    public function getOrderItems($state)
    {
        return $this->orderItemsByState[$state] ?? [];
    }

    /**
     * @param string[] $excludedStates
     * @return SalesOrderItem[]
     */
    public function getOrderItemsExcept(array $excludedStates)
    {
        $orderItems = [];

        foreach ($this->orderItemsByState as $state => $stateOrderItems) {
            if (!in_array($state, $excludedStates, true)) {
                $orderItems += $stateOrderItems;
            }
        }

        return $orderItems;
    }

    /**
     * @param string $state
     * @return bool
     */
    public function hasMarketplaceItems($state)
    {
        return ($this->marketplaceItemCountsByState[$state] ?? 0) > 0;
    }
}
