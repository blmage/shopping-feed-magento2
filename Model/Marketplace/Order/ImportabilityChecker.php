<?php

namespace ShoppingFeed\Manager\Model\Marketplace\Order;

use Magento\Framework\Phrase;
use ShoppingFeed\Manager\Api\Data\Account\StoreInterface;
use ShoppingFeed\Manager\Api\Data\Marketplace\OrderInterface;
use ShoppingFeed\Manager\Model\Sales\Order\ConfigInterface as OrderConfigInterface;

class ImportabilityChecker
{
    /**
     * @var OrderConfigInterface
     */
    private $orderGeneralConfig;

    /**
     * @param OrderConfigInterface $orderGeneralConfig
     */
    public function __construct(OrderConfigInterface $orderGeneralConfig)
    {
        $this->orderGeneralConfig = $orderGeneralConfig;
    }

    /**
     * @param StoreInterface $store
     * @param string $shoppingFeedStatus
     * @param bool $isTest
     * @param bool $isFulfilled
     * @param int|null $salesOrderId
     * @param \DateTime $createdAt
     * @return Phrase|null The reason why the order can not be imported, or null if it can.
     */
    public function getNonImportableReason(
        StoreInterface $store,
        $shoppingFeedStatus,
        $isTest,
        $isFulfilled,
        $salesOrderId,
        \DateTime $createdAt
    ) {
        if (!empty($salesOrderId)) {
            return __('already imported');
        }

        if ($createdAt < $this->orderGeneralConfig->getOrderImportFromDate($store)) {
            return __('too old');
        }

        if ($isTest) {
            if (!$this->orderGeneralConfig->shouldImportTestOrders($store)) {
                return __('import of test orders is disabled');
            }
        } elseif (!$this->orderGeneralConfig->shouldImportLiveOrders($store)) {
            return __('import of live orders is disabled');
        }

        $isShipped = (OrderInterface::STATUS_SHIPPED === $shoppingFeedStatus);

        if ($isFulfilled) {
            $shouldImportPartiallyShipped = $this->orderGeneralConfig->shouldImportPartiallyShippedFulfilledOrders(
                $store
            );

            if (!$this->orderGeneralConfig->shouldImportFulfilledOrders($store)) {
                return __('import of fulfilled orders is disabled');
            } elseif (OrderInterface::STATUS_PARTIALLY_SHIPPED === $shoppingFeedStatus) {
                return $shouldImportPartiallyShipped
                    ? null
                    : __('import of partially shipped fulfilled orders is disabled');
            } elseif (!$isShipped) {
                return $shouldImportPartiallyShipped
                    ? __('status is neither "shipped" nor "partially_shipped"')
                    : __('status is not "shipped"');
            }

            return null;
        }

        if ($isShipped) {
            return $this->orderGeneralConfig->shouldImportShippedOrders($store)
                ? null
                : __('import of shipped orders is disabled');
        }

        if (OrderInterface::STATUS_WAITING_SHIPMENT !== $shoppingFeedStatus) {
            return __('status is not "waiting_shipment"');
        }

        return null;
    }

    /**
     * @param StoreInterface $store
     * @param string $shoppingFeedStatus
     * @param bool $isTest
     * @param bool $isFulfilled
     * @param int|null $salesOrderId
     * @param \DateTime $createdAt
     * @return bool
     */
    public function isImportable(
        StoreInterface $store,
        $shoppingFeedStatus,
        $isTest,
        $isFulfilled,
        $salesOrderId,
        \DateTime $createdAt
    ) {
        return null === $this->getNonImportableReason(
            $store,
            $shoppingFeedStatus,
            $isTest,
            $isFulfilled,
            $salesOrderId,
            $createdAt
        );
    }

    /**
     * @param OrderInterface $order
     * @param StoreInterface $store
     * @return Phrase|null The reason why the order can not be imported, or null if it can.
     */
    public function getOrderNonImportableReason(OrderInterface $order, StoreInterface $store)
    {
        // An unreadable creation date is handled as a very old one.
        $createdAt = \DateTime::createFromFormat('Y-m-d H:i:s', (string) $order->getCreatedAt())
            ?: new \DateTime('@0');

        return $this->getNonImportableReason(
            $store,
            $order->getShoppingFeedStatus(),
            $order->isTest(),
            $order->isFulfilled(),
            $order->getSalesOrderId(),
            $createdAt
        );
    }

    /**
     * @param OrderInterface $order
     * @param StoreInterface $store
     * @return bool
     */
    public function isImportableOrder(OrderInterface $order, StoreInterface $store)
    {
        return null === $this->getOrderNonImportableReason($order, $store);
    }
}
