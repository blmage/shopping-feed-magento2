<?php

namespace ShoppingFeed\Manager\Model\Sales\Order;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface as SalesOrderRepositoryInterface;
use Magento\Sales\Model\Order as SalesOrder;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\Item as SalesOrderItem;
use Magento\Sales\Model\OrderFactory as SalesOrderFactory;
use ShoppingFeed\Manager\Api\Data\Account\StoreInterface;
use ShoppingFeed\Manager\Api\Data\Marketplace\OrderInterface as MarketplaceOrderInterface;
use ShoppingFeed\Manager\Model\Marketplace\Order\Item\StateResolver as MarketplaceItemStateResolver;
use ShoppingFeed\Manager\Model\Marketplace\Order\Manager as MarketplaceOrderManager;
use ShoppingFeed\Manager\Model\ResourceModel\Marketplace\Order as MarketplaceOrderResource;
use ShoppingFeed\Manager\Model\Sales\Order\ConfigInterface as OrderConfigInterface;
use ShoppingFeed\Manager\Model\Sales\Order\Item\MarketplaceItemMapper;
use ShoppingFeed\Manager\Model\Sales\Order\Shipment\FulfilmentShipper;

/**
 * Applies the changes made on the marketplaces to the imported orders.
 *
 * - Cancel and refund actions run at every run, on every order of the syncing window in the matching status: they can
 *   not loop, since the order ends up cancelled or closed.
 * - Everything else only runs once per change, for the kinds of changes recorded by the fetch
 *   (`syncing_pending_changes`): "Block order" actions for a status change, partial refunds for a status or refund
 *   change, shipments of fulfilled orders for a status or shipment change. A shipment blocked by a hold is tried again
 *   at each run, until the hold is released.
 * - The synchronization releases its own holds before cancelling or refunding, never the holds of someone else, and
 *   never in the partial refund path.
 * - Every action is written to the order history.
 */
class Syncer implements SyncerInterface
{
    /**
     * @var OrderConfigInterface
     */
    private $orderGeneralConfig;

    /**
     * @var MarketplaceOrderManager
     */
    private $marketplaceOrderManager;

    /**
     * @var SalesOrderRepositoryInterface
     */
    private $salesOrderRepository;

    /**
     * @var CreditmemoFactory
     */
    private $creditmemoFactory;

    /**
     * @var CreditmemoManagementInterface
     */
    private $creditmemoManager;

    /**
     * @var MarketplaceOrderResource
     */
    private $marketplaceOrderResource;

    /**
     * @var MarketplaceItemMapper
     */
    private $marketplaceItemMapper;

    /**
     * @var FulfilmentShipper
     */
    private $fulfilmentShipper;

    /**
     * @var SalesOrderFactory
     */
    private $salesOrderFactory;

    /**
     * @param ConfigInterface $orderGeneralConfig
     * @param MarketplaceOrderManager $marketplaceOrderManager
     * @param SalesOrderRepositoryInterface $salesOrderRepository
     * @param CreditmemoFactory $creditmemoFactory
     * @param CreditmemoManagementInterface $creditmemoManager
     * @param MarketplaceOrderResource|null $marketplaceOrderResource
     * @param MarketplaceItemMapper|null $marketplaceItemMapper
     * @param FulfilmentShipper|null $fulfilmentShipper
     * @param SalesOrderFactory|null $salesOrderFactory
     */
    public function __construct(
        OrderConfigInterface $orderGeneralConfig,
        MarketplaceOrderManager $marketplaceOrderManager,
        SalesOrderRepositoryInterface $salesOrderRepository,
        CreditmemoFactory $creditmemoFactory,
        CreditmemoManagementInterface $creditmemoManager,
        ?MarketplaceOrderResource $marketplaceOrderResource = null,
        ?MarketplaceItemMapper $marketplaceItemMapper = null,
        ?FulfilmentShipper $fulfilmentShipper = null,
        ?SalesOrderFactory $salesOrderFactory = null
    ) {
        $this->orderGeneralConfig = $orderGeneralConfig;
        $this->marketplaceOrderManager = $marketplaceOrderManager;
        $this->salesOrderRepository = $salesOrderRepository;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoManager = $creditmemoManager;

        $objectManager = ObjectManager::getInstance();

        $this->marketplaceOrderResource = $marketplaceOrderResource
            ?? $objectManager->get(MarketplaceOrderResource::class);

        $this->marketplaceItemMapper = $marketplaceItemMapper
            ?? $objectManager->get(MarketplaceItemMapper::class);

        $this->fulfilmentShipper = $fulfilmentShipper
            ?? $objectManager->get(FulfilmentShipper::class);

        $this->salesOrderFactory = $salesOrderFactory
            ?? $objectManager->get(SalesOrderFactory::class);
    }

    public function synchronizeStoreOrders(array $marketplaceOrders, StoreInterface $store)
    {
        /** @var MarketplaceOrderInterface $marketplaceOrder */
        foreach ($marketplaceOrders as $marketplaceOrder) {
            $retriedChanges = $this->synchronizeStoreOrder($marketplaceOrder, $store);

            if (null !== $retriedChanges) {
                $this->clearSyncingPendingChanges($marketplaceOrder, $retriedChanges);
            }
        }
    }

    /**
     * @param MarketplaceOrderInterface $marketplaceOrder
     * @param int $retriedChanges The changes to synchronize again at the next run.
     */
    private function clearSyncingPendingChanges(MarketplaceOrderInterface $marketplaceOrder, $retriedChanges)
    {
        if (!$marketplaceOrder->isSyncingPending()) {
            return;
        }

        try {
            $clearedChanges = $marketplaceOrder->getSyncingPendingChanges() & ~$retriedChanges;

            $isCleared = $this->marketplaceOrderResource->clearOrderSyncingPendingChanges(
                $marketplaceOrder->getId(),
                $clearedChanges,
                $marketplaceOrder->getSyncingChangeCount(),
                $retriedChanges
            );

            if ($isCleared) {
                $marketplaceOrder->setSyncingPendingChanges($retriedChanges);
            }
        } catch (\Exception $e) {
            $this->marketplaceOrderManager->logOrderError(
                $marketplaceOrder,
                __('Could not synchronize the imported order:') . "\n" . $e->getMessage(),
                (string) $e
            );
        }
    }

    /**
     * @param MarketplaceOrderInterface $marketplaceOrder
     * @param StoreInterface $store
     * @return int|null The pending changes to synchronize again at the next run, or null if the synchronization
     *                  failed (all its pending changes are then left for the next run).
     */
    private function synchronizeStoreOrder(MarketplaceOrderInterface $marketplaceOrder, StoreInterface $store)
    {
        if (!$marketplaceOrder->getSalesOrderId()) {
            return 0;
        }

        try {
            $salesOrder = $this->salesOrderRepository->get($marketplaceOrder->getSalesOrderId());
        } catch (NoSuchEntityException $e) {
            return 0;
        }

        if (!$salesOrder instanceof SalesOrder) {
            return 0;
        }

        $status = $marketplaceOrder->getShoppingFeedStatus();
        $hasStatusChange = $marketplaceOrder->hasSyncingPendingChange(MarketplaceOrderInterface::SYNCING_CHANGE_STATUS);
        $retriedChanges = 0;

        try {
            $this->refreshHeldBySyncing($marketplaceOrder, $salesOrder);

            if (
                $marketplaceOrder->isFulfilled()
                && (
                    $hasStatusChange
                    || $marketplaceOrder->hasSyncingPendingChange(
                        MarketplaceOrderInterface::SYNCING_CHANGE_ITEM_SHIPMENT
                    )
                )
                && !$this->shipFulfilledStoreOrder($marketplaceOrder, $salesOrder, $store)
            ) {
                // Blocked by a hold: try again at each run, until the hold is released.
                $retriedChanges |= MarketplaceOrderInterface::SYNCING_CHANGE_ITEM_SHIPMENT;
            }

            if (MarketplaceOrderInterface::STATUS_PARTIALLY_REFUNDED === $status) {
                // Other changes (an item shipped, for example) do not concern the partial refund.
                if (
                    $hasStatusChange
                    || $marketplaceOrder->hasSyncingPendingChange(MarketplaceOrderInterface::SYNCING_CHANGE_ITEM_REFUND)
                ) {
                    $this->synchronizePartiallyRefundedStoreOrder($marketplaceOrder, $salesOrder, $store);
                }

                return $retriedChanges;
            }

            $action = $this->getStatusSyncingAction($status, $store);

            if (self::SYNCING_ACTION_HOLD === $action) {
                if ($hasStatusChange) {
                    $this->holdStoreOrder($marketplaceOrder, $salesOrder, $store);
                }
            } elseif (
                in_array(
                    $action,
                    [ self::SYNCING_ACTION_CANCEL, self::SYNCING_ACTION_REFUND, self::SYNCING_ACTION_CANCEL_OR_REFUND ],
                    true
                )
            ) {
                // The cancel and refund actions run at every run: only write that they could not be applied once.
                $onHoldComment = $hasStatusChange
                    ? $this->getStatusSentence($status) . ' ' . $this->getActionOnHoldSentence($action)
                    : null;

                if (!$this->releaseSyncingHold($marketplaceOrder, $salesOrder, $onHoldComment)) {
                    return $retriedChanges;
                }

                if (self::SYNCING_ACTION_CANCEL_OR_REFUND === $action) {
                    if ($salesOrder->canCancel()) {
                        $action = self::SYNCING_ACTION_CANCEL;
                    } elseif ($salesOrder->canCreditmemo()) {
                        $action = self::SYNCING_ACTION_REFUND;
                    }
                }

                if (self::SYNCING_ACTION_CANCEL === $action) {
                    $this->cancelStoreOrder($marketplaceOrder, $salesOrder, $store);
                } elseif (self::SYNCING_ACTION_REFUND === $action) {
                    $this->refundStoreOrder($marketplaceOrder, $salesOrder, $store);
                }
            }
        } catch (\Exception $e) {
            $this->marketplaceOrderManager->logOrderError(
                $marketplaceOrder,
                __('Could not synchronize the imported order:') . "\n" . $e->getMessage(),
                (string) $e
            );

            return null;
        }

        return $retriedChanges;
    }

    /**
     * @param string $status
     * @param StoreInterface $store
     * @return string|null
     */
    private function getStatusSyncingAction($status, StoreInterface $store)
    {
        switch ($status) {
            case MarketplaceOrderInterface::STATUS_REFUSED:
                return $this->orderGeneralConfig->getOrderRefusalSyncingAction($store);
            case MarketplaceOrderInterface::STATUS_CANCELLED:
                return $this->orderGeneralConfig->getOrderCancellationSyncingAction($store);
            case MarketplaceOrderInterface::STATUS_REFUNDED:
                return $this->orderGeneralConfig->getOrderRefundSyncingAction($store);
        }

        return null;
    }

    /**
     * @param string $status
     * @return string
     */
    private function getStatusSentence($status)
    {
        switch ($status) {
            case MarketplaceOrderInterface::STATUS_REFUSED:
                return (string) __('Refused on the marketplace.');
            case MarketplaceOrderInterface::STATUS_CANCELLED:
                return (string) __('Cancelled on the marketplace.');
            case MarketplaceOrderInterface::STATUS_REFUNDED:
                return (string) __('Refunded on the marketplace.');
            case MarketplaceOrderInterface::STATUS_PARTIALLY_REFUNDED:
                return (string) __('Partially refunded on the marketplace.');
        }

        return (string) __('Changed on the marketplace.');
    }

    /**
     * @param string $action
     * @return string
     */
    private function getActionOnHoldSentence($action)
    {
        switch ($action) {
            case self::SYNCING_ACTION_CANCEL:
                return (string) __('The order is on hold: it was not cancelled.');
            case self::SYNCING_ACTION_REFUND:
                return (string) __('The order is on hold: it was not refunded.');
        }

        return (string) __('The order is on hold: it was neither cancelled nor refunded.');
    }

    /**
     * @param SalesOrder $salesOrder
     * @return bool
     */
    private function isSalesOrderOnHold(SalesOrder $salesOrder)
    {
        return SalesOrder::STATE_HOLDED === $salesOrder->getState();
    }

    /**
     * @param MarketplaceOrderInterface $marketplaceOrder
     * @param bool $isHeldBySyncing
     */
    private function setIsHeldBySyncing(MarketplaceOrderInterface $marketplaceOrder, $isHeldBySyncing)
    {
        // Only update the flag: the fetch may have refreshed the marketplace order in the meantime.
        $this->marketplaceOrderResource->updateOrderIsHeldBySyncing($marketplaceOrder->getId(), $isHeldBySyncing);
        $marketplaceOrder->setIsHeldBySyncing($isHeldBySyncing);
    }

    /**
     * Forget a hold of the synchronization that was released by someone else.
     *
     * @param MarketplaceOrderInterface $marketplaceOrder
     * @param SalesOrder $salesOrder
     */
    private function refreshHeldBySyncing(MarketplaceOrderInterface $marketplaceOrder, SalesOrder $salesOrder)
    {
        if ($marketplaceOrder->isHeldBySyncing() && !$this->isSalesOrderOnHold($salesOrder)) {
            $this->setIsHeldBySyncing($marketplaceOrder, false);
        }
    }

    /**
     * @param MarketplaceOrderInterface $marketplaceOrder
     * @param SalesOrder $salesOrder
     * @param string|null $onHoldComment The comment to write if the order is held by someone else.
     * @return bool Whether the order is not on hold (anymore).
     */
    private function releaseSyncingHold(
        MarketplaceOrderInterface $marketplaceOrder,
        SalesOrder $salesOrder,
        $onHoldComment
    ) {
        if (!$this->isSalesOrderOnHold($salesOrder)) {
            return true;
        }

        if ($marketplaceOrder->isHeldBySyncing() && $salesOrder->canUnhold()) {
            $salesOrder->unhold();

            $salesOrder->addCommentToStatusHistory(
                (string) __(
                    'The hold put by the Shopping Feed synchronization is released, to apply a change on the marketplace.'
                )
            );

            $this->salesOrderRepository->save($salesOrder);
            $this->setIsHeldBySyncing($marketplaceOrder, false);

            return true;
        }

        if (null !== $onHoldComment) {
            $salesOrder->addCommentToStatusHistory($onHoldComment);
            $this->salesOrderRepository->save($salesOrder);
        }

        return false;
    }

    /**
     * @param MarketplaceOrderInterface $marketplaceOrder
     * @param SalesOrder $salesOrder
     * @param string $comment
     */
    private function holdStoreOrderWithComment(
        MarketplaceOrderInterface $marketplaceOrder,
        SalesOrder $salesOrder,
        $comment
    ) {
        $isHeld = false;

        if ($salesOrder->canHold()) {
            $salesOrder->hold();
            $isHeld = true;
        } elseif (!$this->isSalesOrderOnHold($salesOrder)) {
            // Magento can not hold complete, closed or cancelled orders: the comment remains.
            $comment .= ' ' . __('The order could not be blocked in its current state.');
        }

        $salesOrder->addCommentToStatusHistory($comment);
        $this->salesOrderRepository->save($salesOrder);

        if ($isHeld) {
            $this->setIsHeldBySyncing($marketplaceOrder, true);
        }
    }

    public function holdStoreOrder(
        MarketplaceOrderInterface $marketplaceOrder,
        SalesOrder $salesOrder,
        StoreInterface $store
    ) {
        $this->holdStoreOrderWithComment(
            $marketplaceOrder,
            $salesOrder,
            $this->getStatusSentence($marketplaceOrder->getShoppingFeedStatus())
            . ' ' . __('The order is blocked.')
        );
    }

    public function cancelStoreOrder(
        MarketplaceOrderInterface $marketplaceOrder,
        SalesOrder $salesOrder,
        StoreInterface $store
    ) {
        if ($salesOrder->canCancel()) {
            $salesOrder->cancel();

            $salesOrder->addCommentToStatusHistory(
                $this->getStatusSentence($marketplaceOrder->getShoppingFeedStatus())
                . ' ' . __('The order is cancelled.')
            );

            $this->salesOrderRepository->save($salesOrder);
        }
    }

    public function refundStoreOrder(
        MarketplaceOrderInterface $marketplaceOrder,
        SalesOrder $salesOrder,
        StoreInterface $store
    ) {
        if ($salesOrder->canCreditmemo()) {
            $creditmemo = $this->creditmemoFactory->createByOrder($salesOrder);

            // Stock is never reserved nor deducted for fulfilled orders: returning their items would add stock.
            if (!$marketplaceOrder->isFulfilled()) {
                foreach ($creditmemo->getAllItems() as $creditmemoItem) {
                    $creditmemoItem->setBackToStock(true);
                }
            }

            $this->creditmemoManager->refund($creditmemo, true);

            $salesOrder->addCommentToStatusHistory(
                $this->getStatusSentence($marketplaceOrder->getShoppingFeedStatus())
                . ' ' . __('The order is refunded (credit memo #%1).', $creditmemo->getIncrementId())
            );

            $this->salesOrderRepository->save($salesOrder);
        }
    }

    /**
     * @param MarketplaceOrderInterface $marketplaceOrder
     * @param SalesOrder $salesOrder
     * @param StoreInterface $store
     */
    private function synchronizePartiallyRefundedStoreOrder(
        MarketplaceOrderInterface $marketplaceOrder,
        SalesOrder $salesOrder,
        StoreInterface $store
    ) {
        $action = $this->orderGeneralConfig->getOrderPartialRefundSyncingAction($store);
        $statusSentence = $this->getStatusSentence(MarketplaceOrderInterface::STATUS_PARTIALLY_REFUNDED);

        if (self::SYNCING_ACTION_HOLD === $action) {
            $this->holdStoreOrderWithComment(
                $marketplaceOrder,
                $salesOrder,
                $statusSentence . ' ' . __('Create the credit memo manually.')
            );
        } elseif (self::SYNCING_ACTION_REFUND === $action) {
            // Never release a hold here, even one put by the synchronization: it may have been put for an earlier
            // partial refund that could not be applied, and that is still to be handled manually.
            if ($this->isSalesOrderOnHold($salesOrder)) {
                $salesOrder->addCommentToStatusHistory(
                    $statusSentence . ' ' . __('The order is on hold: the refunded items were not refunded.')
                );

                $this->salesOrderRepository->save($salesOrder);

                return;
            }

            $this->refundStoreOrderItems($marketplaceOrder, $salesOrder);
        }
    }

    /**
     * Refund the items marked as refunded on the marketplace, without shipping fees nor adjustments.
     * Hold the order instead (with a comment) whenever that can not be done with certainty.
     *
     * @param MarketplaceOrderInterface $marketplaceOrder
     * @param SalesOrder $salesOrder
     */
    private function refundStoreOrderItems(MarketplaceOrderInterface $marketplaceOrder, SalesOrder $salesOrder)
    {
        $statusSentence = $this->getStatusSentence(MarketplaceOrderInterface::STATUS_PARTIALLY_REFUNDED);
        $itemMap = $this->marketplaceItemMapper->getMap($marketplaceOrder, $salesOrder);

        if (!$itemMap->isClear()) {
            $failureReason = $itemMap->getUnclearReason();
        } elseif (!$itemMap->hasMarketplaceItems(MarketplaceItemStateResolver::STATE_REFUNDED)) {
            // For example, a refund of the shipping fees only, or a goodwill gesture.
            $failureReason = __('no item is marked as refunded');
        } else {
            $refundedOrderItems = $itemMap->getOrderItems(MarketplaceItemStateResolver::STATE_REFUNDED);
            $quantities = $this->getQuantitiesToRefund($refundedOrderItems);

            if (empty($quantities) && $this->areOrderItemsFullyRefunded($refundedOrderItems)) {
                // Already credited, manually or by an earlier run.
                return;
            }

            if (empty($quantities) || !$salesOrder->canCreditmemo()) {
                $failureReason = __('the order can not be refunded');
            } else {
                try {
                    $creditmemo = $this->creditmemoFactory->createByOrder(
                        $salesOrder,
                        [
                            'qtys' => $quantities,
                            // Otherwise Magento refunds all the remaining shipping fees.
                            'shipping_amount' => 0,
                            'adjustment_positive' => 0,
                            'adjustment_negative' => 0,
                        ]
                    );

                    if (!$marketplaceOrder->isFulfilled()) {
                        foreach ($creditmemo->getAllItems() as $creditmemoItem) {
                            $creditmemoItem->setBackToStock(true);
                        }
                    }

                    $this->creditmemoManager->refund($creditmemo, true);
                } catch (\Exception $e) {
                    // Hold the order rather than failing again at every run. A failed refund may have changed the
                    // order in memory: hold a fresh copy of it.
                    $this->marketplaceOrderManager->logOrderError(
                        $marketplaceOrder,
                        __('Could not synchronize the imported order:') . "\n" . $e->getMessage(),
                        (string) $e
                    );

                    $this->holdStoreOrderWithComment(
                        $marketplaceOrder,
                        $this->salesOrderFactory->create()->load($salesOrder->getId()),
                        $statusSentence
                        . ' '
                        . __(
                            'The refunded items could not be refunded automatically (%1): create the credit memo manually.',
                            $e->getMessage()
                        )
                    );

                    return;
                }

                $salesOrder->addCommentToStatusHistory(
                    $statusSentence
                    . ' '
                    . __(
                        'Refunded items: %1 (credit memo #%2).',
                        $this->marketplaceItemMapper->getItemQuantitiesLabel($refundedOrderItems, $quantities),
                        $creditmemo->getIncrementId()
                    )
                );

                $this->salesOrderRepository->save($salesOrder);

                return;
            }
        }

        $this->holdStoreOrderWithComment(
            $marketplaceOrder,
            $salesOrder,
            $statusSentence
            . ' '
            . __(
                'The refunded items could not be refunded automatically (%1): create the credit memo manually.',
                $failureReason
            )
        );
    }

    /**
     * @param SalesOrderItem[] $orderItems Top-level order items.
     * @return float[] The quantities left to refund, by order item ID, children included (as Magento expects them).
     */
    private function getQuantitiesToRefund(array $orderItems)
    {
        $quantities = [];

        foreach ($orderItems as $orderItem) {
            /** @var SalesOrderItem $item */
            foreach (array_merge([ $orderItem ], $orderItem->getChildrenItems()) as $item) {
                if (($quantity = $item->getQtyToRefund()) > 0) {
                    $quantities[(int) $item->getId()] = $quantity;
                }
            }
        }

        return $quantities;
    }

    /**
     * @param SalesOrderItem[] $orderItems Top-level order items.
     * @return bool
     */
    private function areOrderItemsFullyRefunded(array $orderItems)
    {
        foreach ($orderItems as $orderItem) {
            /** @var SalesOrderItem $item */
            foreach (array_merge([ $orderItem ], $orderItem->getChildrenItems()) as $item) {
                if (
                    !$item->isDummy()
                    && ($item->getQtyRefunded() + $item->getQtyCanceled() < $item->getQtyOrdered() - 0.0001)
                ) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Ship what the marketplace shipped and Magento did not yet.
     *
     * @param MarketplaceOrderInterface $marketplaceOrder
     * @param SalesOrder $salesOrder
     * @param StoreInterface $store
     * @return bool False if the shipment is blocked by a hold (it is then tried again at each run, silently, until
     *              the hold is released).
     * @throws \Exception
     */
    private function shipFulfilledStoreOrder(
        MarketplaceOrderInterface $marketplaceOrder,
        SalesOrder $salesOrder,
        StoreInterface $store
    ) {
        // Shipping what is left once the order is shipped does not depend on partial shipments: otherwise, turning
        // them off would leave the orders they partly shipped partly shipped for good.
        if (!$this->orderGeneralConfig->shouldCreateFulfilmentShipment($store)) {
            return true;
        }

        $orderItems = $this->fulfilmentShipper->getShippableOrderItems(
            $marketplaceOrder,
            $salesOrder,
            $this->orderGeneralConfig->shouldCreatePartialFulfilmentShipments($store)
        );

        $quantities = $this->fulfilmentShipper->getQuantitiesToShip($orderItems);

        if (empty($quantities)) {
            return true;
        }

        if ($this->isSalesOrderOnHold($salesOrder)) {
            return false;
        }

        if ($shipment = $this->fulfilmentShipper->createShipment($salesOrder, $quantities)) {
            $salesOrder->addCommentToStatusHistory(
                (string) __(
                    'Shipped by the marketplace: %1 (shipment #%2).',
                    $this->marketplaceItemMapper->getItemQuantitiesLabel($orderItems, $quantities),
                    $shipment->getIncrementId()
                )
            );

            $this->salesOrderRepository->save($salesOrder);
        }

        return true;
    }
}
