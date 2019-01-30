<?php

namespace ShoppingFeed\Manager\Model\Feed\Product;

use Magento\Catalog\Model\Product as CatalogProduct;
use ShoppingFeed\Manager\Api\Data\Account\StoreInterface;
use ShoppingFeed\Manager\Model\Feed\ConfigInterface as FeedConfigInterface;

class SkuExtractor
{
    /**
     * @var FeedConfigInterface
     */
    private $feedGeneralConfig;

    /**
     * @param FeedConfigInterface $feedGeneralConfig
     */
    public function __construct(FeedConfigInterface $feedGeneralConfig)
    {
        $this->feedGeneralConfig = $feedGeneralConfig;
    }

    /**
     * @param CatalogProduct $product
     * @param StoreInterface $store
     * @return int|string
     */
    public function getCatalogProductSku(CatalogProduct $product, StoreInterface $store)
    {
        return !$this->feedGeneralConfig->shouldUseProductIdForSku($store)
            ? $product->getSku()
            : (int) $product->getId();
    }
}
