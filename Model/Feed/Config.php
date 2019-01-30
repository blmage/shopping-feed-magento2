<?php

namespace ShoppingFeed\Manager\Model\Feed;

use ShoppingFeed\Manager\Api\Data\Account\StoreInterface;
use ShoppingFeed\Manager\Model\Account\Store\ConfigManager;
use ShoppingFeed\Manager\Model\Config\Field\Checkbox;
use ShoppingFeed\Manager\Model\Feed\Product\Section\Type\Attributes as AttributesSectionType;
use ShoppingFeed\Manager\Model\Feed\Product\Section\Config\Attributes as AttributesSectionConfig;

class Config extends AbstractConfig implements ConfigInterface
{
    const KEY_USE_GZIP_COMPRESSION = 'use_gzip_compression';
    const KEY_USE_PRODUCT_ID_FOR_SKU = 'use_product_id_for_sku';

    public function getScopeSubPath()
    {
        return [ 'general' ];
    }

    protected function getBaseFields()
    {
        return [
            $this->fieldFactory->create(
                Checkbox::TYPE_CODE,
                [
                    'name' => self::KEY_USE_GZIP_COMPRESSION,
                    'label' => __('Use Gzip Compression'),
                    'sortOrder' => 10,
                ]
            ),

            $this->fieldFactory->create(
                Checkbox::TYPE_CODE,
                [
                    'name' => self::KEY_USE_PRODUCT_ID_FOR_SKU,
                    'label' => __('Use Product ID for SKU'),
                    'sortOrder' => 20,
                ]
            ),
        ];
    }

    /**
     * @param StoreInterface $store
     * @return bool
     */
    public function shouldUseGzipCompression(StoreInterface $store)
    {
        return $this->getFieldValue($store, self::KEY_USE_GZIP_COMPRESSION);
    }

    /**
     * @param StoreInterface $store
     * @return bool
     */
    public function shouldUseProductIdForSku(StoreInterface $store)
    {
        return $this->getFieldValue($store, self::KEY_USE_PRODUCT_ID_FOR_SKU);
    }

    /**
     * @return string
     */
    public function getFieldsetLabel()
    {
        return __('Feed - General');
    }

    public function upgradeStoreData(StoreInterface $store, ConfigManager $configManager, $moduleVersion)
    {
        if (version_compare($moduleVersion, '0.14.0') < 0) {
            try {
                $attributesSectionConfig = $configManager->getSectionTypeConfig(AttributesSectionType::CODE);
            } catch (\Exception $e) {
                $attributesSectionConfig = null;
            }

            if ($attributesSectionConfig instanceof AttributesSectionConfig) {
                $useProductIdForSku = $store->getConfiguration()
                    ->getDataByPath(
                        $attributesSectionConfig->getFieldValuePath(
                            AttributesSectionConfig::KEY_USE_PRODUCT_ID_FOR_SKU
                        )
                    );

                if (is_bool($useProductIdForSku)) {
                    $this->setFieldValue($store, self::KEY_USE_PRODUCT_ID_FOR_SKU, $useProductIdForSku);
                }
            }
        }
    }
}
