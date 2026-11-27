<?php

namespace ShoppingFeed\Manager\Model\Marketplace\Order\Item;

use ShoppingFeed\Manager\Api\Data\Marketplace\Order\ItemInterface as MarketplaceItemInterface;

/**
 * Interprets the Shopping Feed statuses of marketplace order items.
 *
 * Shopping Feed does not document item statuses. Working assumption, until it does: a status covers the whole quantity
 * of its item, and only the statuses below are known to mean that the item is shipped, refunded or cancelled. Any other
 * status (including none) is handled as pending.
 */
class StateResolver
{
    const STATE_PENDING = 'pending';
    const STATE_SHIPPED = 'shipped';
    const STATE_REFUNDED = 'refunded';
    const STATE_CANCELLED = 'cancelled';

    const DEFAULT_STATUS_STATES = [
        'shipped' => self::STATE_SHIPPED,
        'refunded' => self::STATE_REFUNDED,
        'cancelled' => self::STATE_CANCELLED,
        'refused' => self::STATE_CANCELLED,
    ];

    /**
     * @var string[]
     */
    private $statusStates;

    /**
     * @param string[] $statusStates Additional or replacement states, by Shopping Feed item status.
     */
    public function __construct(array $statusStates = [])
    {
        $this->statusStates = array_merge(self::DEFAULT_STATUS_STATES, $statusStates);
    }

    /**
     * @param string|null $status
     * @return string
     */
    public function getStatusState($status)
    {
        return $this->statusStates[strtolower(trim((string) $status))] ?? self::STATE_PENDING;
    }

    /**
     * @param MarketplaceItemInterface $item
     * @return string
     */
    public function getItemState(MarketplaceItemInterface $item)
    {
        return $this->getStatusState($item->getShoppingFeedStatus());
    }
}
