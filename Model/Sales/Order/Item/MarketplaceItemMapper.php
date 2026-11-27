<?php

namespace ShoppingFeed\Manager\Model\Sales\Order\Item;

use Magento\Sales\Model\Order as SalesOrder;
use Magento\Sales\Model\Order\Item as SalesOrderItem;
use ShoppingFeed\Manager\Api\Data\Marketplace\Order\ItemInterface as MarketplaceItemInterface;
use ShoppingFeed\Manager\Api\Data\Marketplace\OrderInterface as MarketplaceOrderInterface;
use ShoppingFeed\Manager\Model\Marketplace\Order\Item\StateResolver as MarketplaceItemStateResolver;
use ShoppingFeed\Manager\Model\ResourceModel\Marketplace\Order\Item\CollectionFactory as MarketplaceItemCollectionFactory;

class MarketplaceItemMapper
{
    /**
     * @var MarketplaceItemCollectionFactory
     */
    private $marketplaceItemCollectionFactory;

    /**
     * @var MarketplaceItemStateResolver
     */
    private $marketplaceItemStateResolver;

    /**
     * @param MarketplaceItemCollectionFactory $marketplaceItemCollectionFactory
     * @param MarketplaceItemStateResolver $marketplaceItemStateResolver
     */
    public function __construct(
        MarketplaceItemCollectionFactory $marketplaceItemCollectionFactory,
        MarketplaceItemStateResolver $marketplaceItemStateResolver
    ) {
        $this->marketplaceItemCollectionFactory = $marketplaceItemCollectionFactory;
        $this->marketplaceItemStateResolver = $marketplaceItemStateResolver;
    }

    /**
     * Match the top-level items of a sales order with the marketplace items, as last fetched.
     *
     * The match is clear when each marketplace item has the order items imported from it (a marketplace item may have
     * been imported as several order items), for the same total quantity, and each order item belongs to one
     * marketplace item. Shopping Feed may split or merge items over time ("grouped by product and status"): any such
     * change makes the match unclear.
     *
     * @param MarketplaceOrderInterface $marketplaceOrder
     * @param SalesOrder $salesOrder
     * @return MarketplaceItemMap
     */
    public function getMap(MarketplaceOrderInterface $marketplaceOrder, SalesOrder $salesOrder)
    {
        $unclearReason = null;
        $orderItemsByShoppingFeedId = [];

        /** @var SalesOrderItem $orderItem */
        foreach ($salesOrder->getAllVisibleItems() as $orderItem) {
            $shoppingFeedItemId = (int) $orderItem->getProductOptionByCode(
                MarketplaceItemInterface::ORDER_ITEM_OPTION_CODE_SHOPPING_FEED_ITEM_ID
            );

            if ($shoppingFeedItemId > 0) {
                $orderItemsByShoppingFeedId[$shoppingFeedItemId][(int) $orderItem->getId()] = $orderItem;
            } else {
                $unclearReason = __('an order item has no Shopping Feed item ID');
            }
        }

        $marketplaceItems = $this->marketplaceItemCollectionFactory
            ->create()
            ->addOrderIdFilter($marketplaceOrder->getId())
            ->getItems();

        $orderItemsByState = [];
        $marketplaceItemCountsByState = [];

        /** @var MarketplaceItemInterface $marketplaceItem */
        foreach ($marketplaceItems as $marketplaceItem) {
            $state = $this->marketplaceItemStateResolver->getItemState($marketplaceItem);
            $marketplaceItemCountsByState[$state] = ($marketplaceItemCountsByState[$state] ?? 0) + 1;
            $shoppingFeedItemId = (int) $marketplaceItem->getShoppingFeedItemId();

            if (empty($orderItemsByShoppingFeedId[$shoppingFeedItemId])) {
                $unclearReason = __('a marketplace item matches no order item');
                continue;
            }

            $orderedQuantity = 0.0;

            foreach ($orderItemsByShoppingFeedId[$shoppingFeedItemId] as $orderItemId => $orderItem) {
                $orderedQuantity += (float) $orderItem->getQtyOrdered();
                $orderItemsByState[$state][$orderItemId] = $orderItem;
            }

            if (abs($orderedQuantity - $marketplaceItem->getQuantity()) > 0.0001) {
                $unclearReason = __('the quantity of a marketplace item differs from the quantity ordered');
            }

            // A second marketplace item with the same ID will match no order item.
            unset($orderItemsByShoppingFeedId[$shoppingFeedItemId]);
        }

        if (!empty($orderItemsByShoppingFeedId)) {
            $unclearReason = __('an order item matches no marketplace item');
        }

        return new MarketplaceItemMap($orderItemsByState, $marketplaceItemCountsByState, $unclearReason);
    }

    /**
     * @param SalesOrderItem[] $orderItems Top-level order items.
     * @param float[] $quantities Quantities by order item ID, children included.
     * @return string A list such as "2 x SKU-1, 1 x SKU-2", for history comments.
     */
    public function getItemQuantitiesLabel(array $orderItems, array $quantities)
    {
        $labels = [];

        foreach ($orderItems as $orderItem) {
            // Depending on the product type, Magento expects the quantities on the parent or on its children.
            foreach (array_merge([ $orderItem ], $orderItem->getChildrenItems()) as $item) {
                $itemId = (int) $item->getId();

                if (isset($quantities[$itemId]) && ($quantities[$itemId] > 0)) {
                    $labels[] = sprintf('%s x %s', (float) $quantities[$itemId], $item->getSku());
                }
            }
        }

        return implode(', ', $labels);
    }
}
