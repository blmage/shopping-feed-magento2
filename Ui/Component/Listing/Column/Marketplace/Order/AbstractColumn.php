<?php

namespace ShoppingFeed\Manager\Ui\Component\Listing\Column\Marketplace\Order;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Phrase;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column as Column;
use ShoppingFeed\Manager\Api\Data\Account\StoreInterface;
use ShoppingFeed\Manager\Api\Data\Marketplace\OrderInterface;
use ShoppingFeed\Manager\Model\Marketplace\Order\ImportabilityChecker;
use ShoppingFeed\Manager\Model\Sales\Order\ConfigInterface as OrderConfigInterface;
use ShoppingFeed\Manager\Model\ResourceModel\Account\Store\Collection as StoreCollection;
use ShoppingFeed\Manager\Model\ResourceModel\Account\Store\CollectionFactory as StoreCollectionFactory;

abstract class AbstractColumn extends Column
{
    /**
     * @var StoreCollectionFactory
     */
    private $storeCollectionFactory;

    /**
     * @var StoreCollection|null
     */
    static private $storeCollection = null;

    /**
     * @var ImportabilityChecker
     */
    private $importabilityChecker;

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param StoreCollectionFactory $storeCollectionFactory
     * @param OrderConfigInterface $orderGeneralConfig Unused, kept for compatibility.
     * @param array $components
     * @param array $data
     * @param ImportabilityChecker|null $importabilityChecker
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        StoreCollectionFactory $storeCollectionFactory,
        OrderConfigInterface $orderGeneralConfig,
        array $components = [],
        array $data = [],
        ?ImportabilityChecker $importabilityChecker = null
    ) {
        $this->storeCollectionFactory = $storeCollectionFactory;

        $this->importabilityChecker = $importabilityChecker
            ?? ObjectManager::getInstance()->get(ImportabilityChecker::class);

        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @return StoreCollection
     */
    private function getStoreCollection()
    {
        if (null === self::$storeCollection) {
            self::$storeCollection = $this->storeCollectionFactory->create();
            self::$storeCollection->load();
        }

        return self::$storeCollection;
    }

    /**
     * @param array $data
     * @return bool
     */
    private function isTestOrderData(array $data)
    {
        return !empty($data[OrderInterface::IS_TEST])
            || preg_match('/^TEST-/i', $data[OrderInterface::MARKETPLACE_ORDER_NUMBER] ?? '');
    }

    /**
     * @param array $item
     * @return bool
     */
    private function hasImportabilityData(array $item)
    {
        return isset($item[OrderInterface::STORE_ID])
            && isset($item[OrderInterface::SHOPPING_FEED_STATUS])
            && isset($item[OrderInterface::IS_FULFILLED])
            && array_key_exists(OrderInterface::SALES_ORDER_ID, $item)
            && isset($item[OrderInterface::CREATED_AT]);
    }

    /**
     * @param array $item
     * @return Phrase|null The reason why the order can not be imported, or null if it can.
     */
    private function getNonImportableOrderItemReason(array $item)
    {
        /** @var StoreInterface $store */
        $store = $this->getStoreCollection()->getItemById((int) $item[OrderInterface::STORE_ID]);

        if (!$store instanceof StoreInterface) {
            return __('unexisting account');
        }

        return $this->importabilityChecker->getNonImportableReason(
            $store,
            trim($item[OrderInterface::SHOPPING_FEED_STATUS] ?? ''),
            $this->isTestOrderData($item),
            (bool) $item[OrderInterface::IS_FULFILLED],
            (int) $item[OrderInterface::SALES_ORDER_ID],
            // An unreadable creation date is handled as a very old one.
            \DateTime::createFromFormat('Y-m-d H:i:s', (string) $item[OrderInterface::CREATED_AT])
                ?: new \DateTime('@0')
        );
    }

    /**
     * @param array $item
     * @return bool|null
     */
    public function isImportableOrderItem(array $item)
    {
        if ($this->hasImportabilityData($item)) {
            return null === $this->getNonImportableOrderItemReason($item);
        }

        return null;
    }

    /**
     * @param array $item
     * @return string
     */
    public function getOrderItemImportableStatus(array $item)
    {
        if ($this->hasImportabilityData($item)) {
            $reason = $this->getNonImportableOrderItemReason($item);

            return (null === $reason) ? (string) __('Yes') : (string) __('No (%1)', $reason);
        }

        return (string) __('Undetermined (missing data)');
    }
}
