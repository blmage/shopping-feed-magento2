<?php

namespace ShoppingFeed\Manager\Model\Marketplace\Order;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface as TimezoneInterface;
use Psr\Log\LoggerInterface;
use ShoppingFeed\Manager\Api\Data\Account\StoreInterface;
use ShoppingFeed\Manager\Api\Data\Marketplace\Order\AddressInterface;
use ShoppingFeed\Manager\Api\Data\Marketplace\OrderInterface as MarketplaceOrderInterface;
use ShoppingFeed\Manager\Api\Data\Marketplace\OrderInterfaceFactory;
use ShoppingFeed\Manager\Api\Data\Marketplace\Order\AddressInterface as MarketplaceAddressInterface;
use ShoppingFeed\Manager\Api\Data\Marketplace\Order\AddressInterfaceFactory;
use ShoppingFeed\Manager\Api\Data\Marketplace\Order\ItemInterface as MarketplaceItemInterface;
use ShoppingFeed\Manager\Api\Data\Marketplace\Order\ItemInterfaceFactory;
use ShoppingFeed\Manager\Api\Marketplace\OrderRepositoryInterface;
use ShoppingFeed\Manager\Api\Marketplace\Order\AddressRepositoryInterface;
use ShoppingFeed\Manager\Api\Marketplace\Order\ItemRepositoryInterface;
use ShoppingFeed\Manager\DataObject;
use ShoppingFeed\Manager\DataObjectFactory;
use ShoppingFeed\Manager\DB\TransactionFactory;
use ShoppingFeed\Manager\Model\Marketplace\Order\Item\StateResolver as MarketplaceItemStateResolver;
use ShoppingFeed\Manager\Model\ResourceModel\Marketplace\Order as OrderResource;
use ShoppingFeed\Manager\Model\ResourceModel\Marketplace\Order\Item\CollectionFactory as ItemCollectionFactory;
use ShoppingFeed\Manager\Model\Sales\Order\ConfigInterface as OrderConfigInterface;
use ShoppingFeed\Sdk\Api\Order\OrderResource as ApiOrder;
use ShoppingFeed\Sdk\Api\Order\OrderItem as ApiItem;

class Importer
{
    const PAYMENT_TAX_MODE_EXCLUDED = 'tax_excluded';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var TimezoneInterface
     */
    private $localeDate;

    /**
     * @var DataObjectFactory
     */
    private $dataObjectFactory;

    /**
     * @var OrderConfigInterface
     */
    private $orderGeneralConfig;

    /**
     * @var OrderInterfaceFactory
     */
    private $orderFactory;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var AddressInterfaceFactory
     */
    private $addressFactory;

    /**
     * @var AddressRepositoryInterface
     */
    private $addressRepository;

    /**
     * @var ItemInterfaceFactory
     */
    private $itemFactory;

    /**
     * @var ItemRepositoryInterface
     */
    private $itemRepository;

    /**
     * @var ItemCollectionFactory
     */
    private $itemCollectionFactory;

    /**
     * @var TransactionFactory
     */
    private $transactionFactory;

    /**
     * @var OrderResource
     */
    private $orderResource;

    /**
     * @var MarketplaceItemStateResolver
     */
    private $marketplaceItemStateResolver;

    /**
     * @param LoggerInterface $logger
     * @param TimezoneInterface $localeDate
     * @param DataObjectFactory $dataObjectFactory
     * @param OrderConfigInterface $orderGeneralConfig
     * @param OrderInterfaceFactory $orderFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param AddressInterfaceFactory $addressFactory
     * @param AddressRepositoryInterface $addressRepository
     * @param ItemInterfaceFactory $itemFactory
     * @param ItemRepositoryInterface $itemRepository
     * @param ItemCollectionFactory $itemCollectionFactory
     * @param TransactionFactory $transactionFactory
     * @param OrderResource|null $orderResource
     * @param MarketplaceItemStateResolver|null $marketplaceItemStateResolver
     */
    public function __construct(
        LoggerInterface $logger,
        TimezoneInterface $localeDate,
        DataObjectFactory $dataObjectFactory,
        OrderConfigInterface $orderGeneralConfig,
        OrderInterfaceFactory $orderFactory,
        OrderRepositoryInterface $orderRepository,
        AddressInterfaceFactory $addressFactory,
        AddressRepositoryInterface $addressRepository,
        ItemInterfaceFactory $itemFactory,
        ItemRepositoryInterface $itemRepository,
        ItemCollectionFactory $itemCollectionFactory,
        TransactionFactory $transactionFactory,
        ?OrderResource $orderResource = null,
        ?MarketplaceItemStateResolver $marketplaceItemStateResolver = null
    ) {
        $this->logger = $logger;
        $this->localeDate = $localeDate;
        $this->dataObjectFactory = $dataObjectFactory;
        $this->orderGeneralConfig = $orderGeneralConfig;
        $this->orderFactory = $orderFactory;
        $this->orderRepository = $orderRepository;
        $this->addressFactory = $addressFactory;
        $this->addressRepository = $addressRepository;
        $this->itemFactory = $itemFactory;
        $this->itemRepository = $itemRepository;
        $this->itemCollectionFactory = $itemCollectionFactory;
        $this->transactionFactory = $transactionFactory;
        $this->orderResource = $orderResource ?? ObjectManager::getInstance()->get(OrderResource::class);

        $this->marketplaceItemStateResolver = $marketplaceItemStateResolver
            ?? ObjectManager::getInstance()->get(MarketplaceItemStateResolver::class);
    }

    /**
     * @param ApiOrder[] $apiOrders
     * @param StoreInterface $store
     * @param bool $updateOnly
     * @throws \Exception
     */
    public function importStoreOrders($apiOrders, StoreInterface $store, $updateOnly = false)
    {
        foreach ($apiOrders as $apiOrder) {
            try {
                $this->importApiOrder($apiOrder, $store, $updateOnly);
            } catch (\Exception $e) {
                $this->logger->critical($e);
            }
        }
    }

    /**
     * @param StoreInterface $store
     * @return string
     */
    public function getDefaultCurrencyCode(StoreInterface $store)
    {
        return $store->getBaseStore()->getCurrentCurrencyCode();
    }

    /**
     * @param StoreInterface $store
     * @return string
     */
    public function getDefaultPaymentMethod(StoreInterface $store)
    {
        return (string) __('Marketplace');
    }

    /**
     * @param StoreInterface $store
     * @return string
     */
    public function getDefaultShippingMethod(StoreInterface $store)
    {
        return (string) __('Marketplace');
    }

    /**
     * @param ApiOrder $apiOrder
     * @return bool
     */
    public function isFulfilledApiOrder(ApiOrder $apiOrder)
    {
        $orderData = $apiOrder->toArray();

        $marketplace = strtolower(trim($apiOrder->getChannel()->getName()));
        $paymentMethod = strtolower(trim($apiOrder->getPaymentInformation()['method'] ?? ''));
        $fulfilmentType = ($orderData['fulfilledBy'] ?? MarketplaceOrderInterface::FULFILMENT_TYPE_UNDEFINED);
        $additionalFields = !is_array($orderData['additionalFields']) ? [] : $orderData['additionalFields'];

        return
            // Any marketplace
            (MarketplaceOrderInterface::FULFILMENT_TYPE_MARKETPLACE === $fulfilmentType)
            // Amazon
            || (('amazon' === $marketplace)
                && ('afn' === $paymentMethod))
            // Cdiscount
            || (('cdiscount' === $marketplace)
                && ('clogistique' === $paymentMethod))
            // ManoMano
            || (in_array($marketplace, [ 'manomanopro', 'monechelle' ])
                && (strtolower(trim($additionalFields['env'] ?? '')) === 'epmm'));
    }

    /**
     * @param ApiOrder $apiOrder
     * @return bool
     */
    public function isTaxExcludedBusinessApiOrder(ApiOrder $apiOrder)
    {
        $paymentData = $apiOrder->getPaymentInformation();
        $paymentMode = trim((string) ($paymentData['taxMode'] ?? ''));

        if (strtolower($paymentMode) !== self::PAYMENT_TAX_MODE_EXCLUDED) {
            return false;
        }

        foreach ($apiOrder->getItems() as $apiItem) {
            if ((float) $apiItem->getTaxAmount() > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param ApiOrder $apiOrder
     * @param StoreInterface $store
     * @param bool $updateOnly
     * @throws \Exception
     */
    public function importApiOrder(ApiOrder $apiOrder, StoreInterface $store, $updateOnly)
    {
        try {
            $marketplaceOrder = $this->orderRepository->getByStoreAndMarketplaceIdAndNumber(
                $store->getId(),
                $apiOrder->getChannel()->getId(),
                $apiOrder->getReference()
            );

            $this->importExistingApiOrder($apiOrder, $marketplaceOrder, $store);
        } catch (NoSuchEntityException $e) {
            if (!$updateOnly) {
                $this->importNewApiOrder($apiOrder, $store);
            }
        }
    }

    /**
     * @param ApiOrder $apiOrder
     * @param StoreInterface $store
     * @throws \Exception
     */
    public function importNewApiOrder(ApiOrder $apiOrder, StoreInterface $store)
    {
        $marketplaceOrder = $this->orderFactory->create();

        $marketplaceOrder->setIsFulfilled($this->isFulfilledApiOrder($apiOrder));

        $this->importApiBaseOrderData($apiOrder, $marketplaceOrder, $store);

        $this->importApiPaymentAndShipmentOrderData($apiOrder, $marketplaceOrder, $store);

        $billingAddress = $this->importApiOrderAddress(
            MarketplaceAddressInterface::TYPE_BILLING,
            $apiOrder->getBillingAddress(),
            $store
        );

        $shippingAddress = $this->importApiOrderAddress(
            MarketplaceAddressInterface::TYPE_SHIPPING,
            $apiOrder->getShippingAddress(),
            $store
        );

        $items = [];
        $referenceAliases = (array) $apiOrder->getItemsReferencesAliases();

        foreach ($apiOrder->getItems() as $apiItem) {
            $items[] = $this->importApiOrderItem($apiItem, $referenceAliases, $store);
        }

        $apiOrderData = $apiOrder->toArray();
        $hasAdditionalFields = !empty($apiOrderData['additionalFields']) && is_array($apiOrderData['additionalFields']);
        $isTaxExcludedBusinessOrder = $this->isTaxExcludedBusinessApiOrder($apiOrder);

        if ($hasAdditionalFields || $isTaxExcludedBusinessOrder) {
            $additionalFields = $this->dataObjectFactory->create();

            if ($hasAdditionalFields) {
                $additionalFields->setData($apiOrderData['additionalFields']);
            }

            if ($isTaxExcludedBusinessOrder) {
                $additionalFields->setData(MarketplaceOrderInterface::ADDITIONAL_FIELD_IS_BUSINESS_ORDER, '1');
            }

            $this->importApiAdditionalOrderData($apiOrder, $marketplaceOrder, $additionalFields, $store);
        }

        $transaction = $this->transactionFactory->create();
        $transaction->addModelResource($marketplaceOrder);

        $transaction->addCommitCallback(
            function () use ($marketplaceOrder, $billingAddress, $shippingAddress, $items) {
                $this->orderRepository->save($marketplaceOrder);
                $marketplaceOrderId = $marketplaceOrder->getId();

                $this->addressRepository->save($billingAddress->setOrderId($marketplaceOrderId));
                $this->addressRepository->save($shippingAddress->setOrderId($marketplaceOrderId));

                /** @var MarketplaceItemInterface $item */
                foreach ($items as $item) {
                    $this->itemRepository->save($item->setOrderId($marketplaceOrderId));
                }
            }
        );

        $transaction->save();
    }

    /**
     * @param ApiOrder $apiOrder
     * @param MarketplaceOrderInterface $marketplaceOrder
     * @param StoreInterface $store
     */
    public function importApiBaseOrderData(
        ApiOrder $apiOrder,
        MarketplaceOrderInterface $marketplaceOrder,
        StoreInterface $store
    ) {
        $marketplaceOrder->setStoreId($store->getId());
        $marketplaceOrder->setShoppingFeedOrderId($apiOrder->getId());
        $marketplaceOrder->setMarketplaceOrderNumber($apiOrder->getReference());
        $marketplaceOrder->setImportRemainingTryCount(MarketplaceOrderInterface::DEFAULT_IMPORT_TRY_COUNT);

        $channel = $apiOrder->getChannel();
        $marketplaceOrder->setShoppingFeedMarketplaceId($channel->getId());
        $marketplaceOrder->setMarketplaceName($channel->getName());
        $marketplaceOrder->setShoppingFeedStatus($apiOrder->getStatus());

        $orderData = $apiOrder->toArray();

        if (!empty($orderData['isTest'])) {
            $marketplaceOrder->setIsTest(true);
        }

        if (
            !empty($orderData['latestShipDate'])
            && preg_match('/^(\d{4}-\d{2}-\d{2})([^\d]|$)/', $orderData['latestShipDate'], $matches)
        ) {
            $marketplaceOrder->setLatestShipDate(new \DateTimeImmutable($matches[0], new \DateTimeZone('UTC')));
        } else {
            $marketplaceOrder->setLatestShipDate(null);
        }

        $marketplaceOrder->setCreatedAt($apiOrder->getCreatedAt()->format('Y-m-d H:i:s'));
        $marketplaceOrder->setUpdatedAt($apiOrder->getUpdatedAt()->format('Y-m-d H:i:s'));
        $marketplaceOrder->setFetchedAt($this->localeDate->date(null, null, false)->format('Y-m-d H:i:s'));
    }

    /**
     * @param ApiOrder $apiOrder
     * @param MarketplaceOrderInterface $marketplaceOrder
     * @param StoreInterface $store
     */
    public function importApiPaymentAndShipmentOrderData(
        ApiOrder $apiOrder,
        MarketplaceOrderInterface $marketplaceOrder,
        StoreInterface $store
    ) {
        $paymentData = $apiOrder->getPaymentInformation();
        $productAmount = (float) $paymentData['productAmount'] ?? 0.0;
        $shippingAmount = (float) $paymentData['shippingAmount'] ?? 0.0;
        $totalAmount = (float) $paymentData['totalAmount'] ?? ($productAmount + $shippingAmount);

        $marketplaceOrder->setProductAmount($productAmount);
        $marketplaceOrder->setShippingAmount($shippingAmount);
        $marketplaceOrder->setTotalAmount($totalAmount);
        $marketplaceOrder->setCurrencyCode($paymentData['currency'] ?? $this->getDefaultCurrencyCode($store));
        $marketplaceOrder->setPaymentMethod($paymentData['method'] ?? $this->getDefaultPaymentMethod($store));

        $shipmentData = $apiOrder->getShipment();
        $marketplaceOrder->setShipmentCarrier($shipmentData['carrier'] ?? $this->getDefaultShippingMethod($store));
    }

    /**
     * @param ApiOrder $apiOrder
     * @param MarketplaceOrderInterface $marketplaceOrder
     * @param DataObject $additionalFields
     * @param StoreInterface $store
     */
    public function importApiAdditionalOrderData(
        ApiOrder $apiOrder,
        MarketplaceOrderInterface $marketplaceOrder,
        DataObject $additionalFields,
        StoreInterface $store
    ) {
        $feesAmount = 0.0;

        if ($additionalFields->hasData('FRAISTRAITEMENT')) {
            $feesAmount += max(0.0, (float) $additionalFields->getData('FRAISTRAITEMENT'));
        }

        if ($additionalFields->hasData('INTERETBCA')) {
            $feesAmount += max(0.0, (float) $additionalFields->getData('INTERETBCA'));
        }

        $marketplaceOrder->setFeesAmount($feesAmount);
        $marketplaceOrder->setAdditionalFields($additionalFields);
    }

    /**
     * @param string $type
     * @param array $apiAddressData
     * @param StoreInterface $store
     * @param MarketplaceAddressInterface|null $address
     * @return MarketplaceAddressInterface
     */
    public function importApiOrderAddress(
        $type,
        array $apiAddressData,
        StoreInterface $store,
        ?AddressInterface $address = null
    ) {
        $firstName = trim($apiAddressData['firstName'] ?? '');
        $lastName = trim($apiAddressData['lastName'] ?? '');

        if (('' === $firstName) && $this->orderGeneralConfig->shouldSplitLastNameWhenEmptyFirstName($store)) {
            $nameParts = preg_split('/\s+/', $lastName, 2);

            if (isset($nameParts[1])) {
                $firstName = $nameParts[0];
                $lastName = $nameParts[1];
            }
        }

        $streetLines = [
            $apiAddressData['street'] ?? '',
            $apiAddressData['street2'] ?? '',
        ];

        if (null === $address) {
            $address = $this->addressFactory->create();
        }

        $address->setType($type);
        $address->setFirstName($firstName);
        $address->setLastName($lastName);
        $address->setCompany($apiAddressData['company'] ?? '');
        $address->setStreet(trim(implode("\n", array_map('trim', $streetLines))));
        $address->setProvince($apiAddressData['province'] ?? null);
        $address->setPostalCode($apiAddressData['postalCode'] ?? '');
        $address->setCity($apiAddressData['city'] ?? '');
        $address->setCountryCode($apiAddressData['country'] ?? '');
        $address->setPhone($apiAddressData['phone'] ?? '');
        $address->setMobilePhone($apiAddressData['mobilePhone'] ?? '');
        $address->setEmail($apiAddressData['email'] ?? '');
        $address->setRelayPointId($apiAddressData['relayId'] ?? '');
        $address->setMiscData($apiAddressData['other'] ?? '');

        return $address;
    }

    /**
     * @param ApiItem $apiItem
     * @param array $referenceAliases
     * @return string
     */
    public function getApiItemReference(ApiItem $apiItem, array $referenceAliases)
    {
        $reference = $apiItem->getReference();

        return $referenceAliases[$reference] ?? $reference;
    }

    /**
     * @param ApiItem $apiItem
     * @return string|null The status (possibly empty), or null if it is not available.
     */
    public function getApiItemStatus(ApiItem $apiItem)
    {
        // Item statuses are not available in older versions of the SDK.
        return method_exists($apiItem, 'getStatus') ? trim((string) $apiItem->getStatus()) : null;
    }

    /**
     * @param ApiItem $apiItem
     * @param array $referenceAliases
     * @param StoreInterface $store
     * @param MarketplaceItemInterface|null $item
     * @return MarketplaceItemInterface
     */
    public function importApiOrderItem(
        ApiItem $apiItem,
        array $referenceAliases,
        StoreInterface $store,
        ?MarketplaceItemInterface $item = null
    ) {
        $item = (null !== $item) ? $item : $this->itemFactory->create();

        $item->setShoppingFeedItemId($apiItem->getId());
        $item->setShoppingFeedStatus($this->getApiItemStatus($apiItem));
        $item->setReference($this->getApiItemReference($apiItem, $referenceAliases));
        $item->setQuantity($apiItem->getQuantity());
        $item->setPrice($apiItem->getUnitPrice());
        $item->setTaxAmount($apiItem->getTaxAmount());

        $additionalData = $apiItem->getAdditionalFields();

        if (is_array($additionalData) && !empty($additionalData)) {
            $additionalFields = $this->dataObjectFactory->create();
            $additionalFields->setData($additionalData);
            $item->setAdditionalFields($additionalFields);
        }

        return $item;
    }

    /**
     * @param ApiOrder $apiOrder
     * @param MarketplaceOrderInterface $marketplaceOrder
     * @param StoreInterface $store
     * @throws \Exception
     */
    public function importExistingApiOrder(
        ApiOrder $apiOrder,
        MarketplaceOrderInterface $marketplaceOrder,
        StoreInterface $store
    ) {
        $orderId = $marketplaceOrder->getId();
        $isImported = !empty($marketplaceOrder->getSalesOrderId());

        $billingAddress = null;
        $shippingAddress = null;
        $savableItems = [];
        $deletableItems = [];

        if (!$isImported) {
            $this->importApiPaymentAndShipmentOrderData($apiOrder, $marketplaceOrder, $store);

            if ($this->orderGeneralConfig->shouldSyncNonImportedAddresses($store)) {
                try {
                    $billingAddress = $this->addressRepository->getByOrderIdAndType(
                        $orderId,
                        AddressInterface::TYPE_BILLING
                    );
                } catch (NoSuchEntityException $e) {
                    $billingAddress = $this->addressFactory->create();
                    $billingAddress->setOrderId($orderId);
                }

                try {
                    $shippingAddress = $this->addressRepository->getByOrderIdAndType(
                        $marketplaceOrder->getId(),
                        AddressInterface::TYPE_SHIPPING
                    );
                } catch (NoSuchEntityException $e) {
                    $shippingAddress = $this->addressFactory->create();
                    $shippingAddress->setOrderId($orderId);
                }

                $this->importApiOrderAddress(
                    AddressInterface::TYPE_BILLING,
                    $apiOrder->getBillingAddress(),
                    $store,
                    $billingAddress
                );

                $this->importApiOrderAddress(
                    AddressInterface::TYPE_SHIPPING,
                    $apiOrder->getShippingAddress(),
                    $store,
                    $shippingAddress
                );
            }
        }

        $referenceAliases = (array) $apiOrder->getItemsReferencesAliases();
        $itemCollection = $this->itemCollectionFactory->create();
        $itemCollection->addOrderIdFilter($orderId);
        $storedItems = $itemCollection->getItems();
        $itemComparison = $this->compareApiOrderItems($apiOrder, $storedItems, $referenceAliases);

        if ($isImported || $this->orderGeneralConfig->shouldSyncNonImportedItems($store)) {
            // The items of imported orders always mirror the marketplace: the synchronization compares them with the
            // items of the sales order to know what was shipped or refunded.
            foreach ($itemComparison['matches'] as list($apiItem, $storedItem)) {
                $savableItems[] = $this->importApiOrderItem($apiItem, $referenceAliases, $store, $storedItem);
            }

            $deletableItems = $itemComparison['unmatched_stored_items'];
        } else {
            foreach ($itemComparison['matches'] as list($apiItem, $storedItem)) {
                if (null !== $storedItem) {
                    $savableItems[] = $storedItem->setShoppingFeedStatus($this->getApiItemStatus($apiItem));
                }
            }
        }

        $syncingChanges = 0;

        if ($isImported) {
            if ($marketplaceOrder->getShoppingFeedStatus() !== $apiOrder->getStatus()) {
                $syncingChanges |= MarketplaceOrderInterface::SYNCING_CHANGE_STATUS;
            }

            // Statuses stored for the first time (orders fetched before item statuses were stored) are not a change.
            if (!$itemComparison['is_first_refresh']) {
                $syncingChanges |= $itemComparison['syncing_changes'];
            }

            if ($syncingChanges > 0) {
                $marketplaceOrder->setSyncingPendingChanges(
                    $marketplaceOrder->getSyncingPendingChanges() | $syncingChanges
                );
            }
        }

        $marketplaceOrder->setShoppingFeedStatus($apiOrder->getStatus());
        $marketplaceOrder->setUpdatedAt($apiOrder->getUpdatedAt()->format('Y-m-d H:i:s'));

        $transaction = $this->transactionFactory->create();
        $transaction->addModelResource($marketplaceOrder);

        $transaction->addCommitCallback(
            function () use (
                $marketplaceOrder,
                $orderId,
                $billingAddress,
                $shippingAddress,
                $savableItems,
                $deletableItems,
                $syncingChanges
            ) {
                $this->orderRepository->save($marketplaceOrder);

                // The syncing state is not written by the save (see the resource model).
                $this->orderResource->addOrderSyncingPendingChanges($orderId, $syncingChanges);

                if (null !== $billingAddress) {
                    $this->addressRepository->save($billingAddress);
                }

                if (null !== $shippingAddress) {
                    $this->addressRepository->save($shippingAddress);
                }

                /** @var MarketplaceItemInterface $savableItem */
                foreach ($savableItems as $savableItem) {
                    $savableItem->setOrderId($orderId);
                    $this->itemRepository->save($savableItem);
                }

                foreach ($deletableItems as $deletableItem) {
                    $this->itemRepository->delete($deletableItem);
                }
            }
        );

        $transaction->save();
    }

    /**
     * @param ApiItem $apiItem
     * @param string $reference
     * @param MarketplaceItemInterface[] $storedItems The candidate items. The matching item is removed from them.
     * @return MarketplaceItemInterface|null
     */
    private function extractMatchingStoredItem(ApiItem $apiItem, $reference, array &$storedItems)
    {
        $apiItemId = (int) $apiItem->getId();

        if ($apiItemId > 0) {
            foreach ($storedItems as $key => $storedItem) {
                if ($storedItem->getShoppingFeedItemId() === $apiItemId) {
                    unset($storedItems[$key]);

                    return $storedItem;
                }
            }
        }

        // Only fall back on the reference when one of the two items has no ID: two items with different IDs are
        // different items, even when they share the same reference (the API groups items by product and status).
        foreach ($storedItems as $key => $storedItem) {
            if (
                (($apiItemId <= 0) || (null === $storedItem->getShoppingFeedItemId()))
                && ($storedItem->getReference() === $reference)
            ) {
                unset($storedItems[$key]);

                return $storedItem;
            }
        }

        return null;
    }

    /**
     * @param ApiOrder $apiOrder
     * @param MarketplaceItemInterface[] $storedItems
     * @param array $referenceAliases
     * @return array
     */
    private function compareApiOrderItems(ApiOrder $apiOrder, array $storedItems, array $referenceAliases)
    {
        $isFirstRefresh = !empty($storedItems);

        foreach ($storedItems as $storedItem) {
            if (null !== $storedItem->getShoppingFeedStatus()) {
                $isFirstRefresh = false;
                break;
            }
        }

        $matches = [];
        $unmatchedStoredItems = $storedItems;
        $syncingChanges = 0;
        $hasShapeChanged = false;

        foreach ($apiOrder->getItems() as $apiItem) {
            $storedItem = $this->extractMatchingStoredItem(
                $apiItem,
                $this->getApiItemReference($apiItem, $referenceAliases),
                $unmatchedStoredItems
            );

            if (null === $storedItem) {
                $hasShapeChanged = true;
            } else {
                if (abs($storedItem->getQuantity() - (float) $apiItem->getQuantity()) > 0.0001) {
                    $hasShapeChanged = true;
                }

                $storedStatus = $storedItem->getShoppingFeedStatus();
                $apiStatus = $this->getApiItemStatus($apiItem);

                if ((null !== $storedStatus) && ($storedStatus !== $apiStatus)) {
                    // Any item change that is not a shipment may be part of a refund: rather alert for nothing than
                    // miss a refund, since item statuses are not documented.
                    $isShipped = MarketplaceItemStateResolver::STATE_SHIPPED
                        === $this->marketplaceItemStateResolver->getStatusState($apiStatus);

                    $syncingChanges |= $isShipped
                        ? MarketplaceOrderInterface::SYNCING_CHANGE_ITEM_SHIPMENT
                        : MarketplaceOrderInterface::SYNCING_CHANGE_ITEM_REFUND;
                }
            }

            $matches[] = [ $apiItem, $storedItem ];
        }

        if (!empty($unmatchedStoredItems)) {
            $hasShapeChanged = true;
        }

        if ($hasShapeChanged) {
            // Items split, merged, added or removed: anything may have been shipped or refunded.
            $syncingChanges |= MarketplaceOrderInterface::SYNCING_CHANGE_ITEM_SHIPMENT
                | MarketplaceOrderInterface::SYNCING_CHANGE_ITEM_REFUND;
        }

        return [
            'matches' => $matches,
            'unmatched_stored_items' => $unmatchedStoredItems,
            'is_first_refresh' => $isFirstRefresh,
            'syncing_changes' => $syncingChanges,
            'has_shape_changed' => $hasShapeChanged,
        ];
    }
}
