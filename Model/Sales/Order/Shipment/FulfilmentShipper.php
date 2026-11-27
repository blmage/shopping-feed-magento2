<?php

namespace ShoppingFeed\Manager\Model\Sales\Order\Shipment;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order as SalesOrder;
use Magento\Sales\Model\Order\Item as SalesOrderItem;
use Magento\Sales\Model\Order\Shipment as SalesShipment;
use Magento\Sales\Model\Order\ShipmentFactory as SalesShipmentFactory;
use ShoppingFeed\Manager\Api\Data\Marketplace\OrderInterface as MarketplaceOrderInterface;
use ShoppingFeed\Manager\DB\TransactionFactory;
use ShoppingFeed\Manager\Model\Marketplace\Order\Item\StateResolver as MarketplaceItemStateResolver;
use ShoppingFeed\Manager\Model\Sales\Order\Item\MarketplaceItemMapper;

/**
 * Ships the orders fulfilled by the marketplaces, as the marketplaces ship them.
 *
 * These shipments are bookkeeping only: they are never sent to Shopping Feed, and deduct no stock.
 */
class FulfilmentShipper
{
    /**
     * @var MarketplaceItemMapper
     */
    private $marketplaceItemMapper;

    /**
     * @var SalesShipmentFactory
     */
    private $salesShipmentFactory;

    /**
     * @var TransactionFactory
     */
    private $transactionFactory;

    /**
     * @param MarketplaceItemMapper $marketplaceItemMapper
     * @param SalesShipmentFactory $salesShipmentFactory
     * @param TransactionFactory $transactionFactory
     */
    public function __construct(
        MarketplaceItemMapper $marketplaceItemMapper,
        SalesShipmentFactory $salesShipmentFactory,
        TransactionFactory $transactionFactory
    ) {
        $this->marketplaceItemMapper = $marketplaceItemMapper;
        $this->salesShipmentFactory = $salesShipmentFactory;
        $this->transactionFactory = $transactionFactory;
    }

    /**
     * The order items shipped by the marketplace and not shipped yet in Magento:
     * - once the order is shipped: every item, except those refunded or cancelled;
     * - before that, if partial shipments are allowed: the items marked as shipped.
     * When the items can not be matched with certainty, nothing is shipped before the order is shipped, and then
     * everything is.
     *
     * @param MarketplaceOrderInterface $marketplaceOrder
     * @param SalesOrder $salesOrder
     * @param bool $isPartialShipmentAllowed
     * @return SalesOrderItem[] The top-level order items to ship (children follow their parent).
     */
    public function getShippableOrderItems(
        MarketplaceOrderInterface $marketplaceOrder,
        SalesOrder $salesOrder,
        $isPartialShipmentAllowed
    ) {
        $isOrderShipped = (MarketplaceOrderInterface::STATUS_SHIPPED === $marketplaceOrder->getShoppingFeedStatus());

        if (!$isOrderShipped && !$isPartialShipmentAllowed) {
            return [];
        }

        $itemMap = $this->marketplaceItemMapper->getMap($marketplaceOrder, $salesOrder);

        if ($isOrderShipped) {
            if (!$itemMap->isClear()) {
                return $salesOrder->getAllVisibleItems();
            }

            return $itemMap->getOrderItemsExcept(
                [
                    MarketplaceItemStateResolver::STATE_REFUNDED,
                    MarketplaceItemStateResolver::STATE_CANCELLED,
                ]
            );
        }

        return $itemMap->isClear() ? $itemMap->getOrderItems(MarketplaceItemStateResolver::STATE_SHIPPED) : [];
    }

    /**
     * @param SalesOrderItem[] $orderItems Top-level order items.
     * @return float[] The quantities left to ship, by order item ID, children included (as Magento expects them).
     */
    public function getQuantitiesToShip(array $orderItems)
    {
        $quantities = [];

        foreach ($orderItems as $orderItem) {
            $items = array_merge([ $orderItem ], $orderItem->getChildrenItems());

            /** @var SalesOrderItem $item */
            foreach ($items as $item) {
                $quantity = $item->getQtyToShip();

                if (($quantity > 0) && !$item->getIsVirtual()) {
                    $quantities[(int) $item->getId()] = $quantity;
                }
            }
        }

        return $quantities;
    }

    /**
     * @param SalesOrder $salesOrder
     * @param float[] $quantities Quantities by order item ID.
     * @return SalesShipment|null
     * @throws LocalizedException
     * @throws \Exception
     */
    public function createShipment(SalesOrder $salesOrder, array $quantities)
    {
        if (empty($quantities) || !$salesOrder->canShip()) {
            return null;
        }

        $shipment = $this->salesShipmentFactory->create($salesOrder, $quantities);
        $shipment->register();

        $transaction = $this->transactionFactory->create();
        $transaction->addObject($shipment);
        $transaction->addObject($salesOrder);
        $transaction->save();

        return $shipment;
    }
}
