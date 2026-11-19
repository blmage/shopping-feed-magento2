<?php

namespace ShoppingFeed\Manager\Model\Feed\Product\Section\Adapter;

use Magento\Bundle\Model\Product\Price as BundleProductPrice;
use Magento\Bundle\Model\Product\Type as BundleProductType;
use Magento\Bundle\Model\Selection as BundleProductSelection;
use Magento\Catalog\Model\Product as CatalogProduct;
use Magento\Catalog\Model\ResourceModel\Product as CatalogProductResource;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\ProductFactory as CatalogProductResourceFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableProductType;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface as TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Api\TaxCalculationInterface as TaxCalculationInterface;
use Magento\Tax\Model\Calculation as TaxCalculation;
use Magento\Tax\Model\Config as TaxConfig;
use Magento\Weee\Model\Config as WeeeConfig;
use ShoppingFeed\Feed\Product\AbstractProduct as AbstractExportedProduct;
use ShoppingFeed\Manager\Api\Data\Account\StoreInterface;
use ShoppingFeed\Manager\Model\Feed\Product\Attribute\Value\AbstractRenderer as AbstractAttributeRenderer;
use ShoppingFeed\Manager\Model\Feed\Product\Attribute\Value\RendererPoolInterface as AttributeRendererPoolInterface;
use ShoppingFeed\Manager\Model\Feed\Product\Section\AbstractAdapter;
use ShoppingFeed\Manager\Model\Feed\Product\Section\Config\PricesInterface as ConfigInterface;
use ShoppingFeed\Manager\Model\Feed\Product\Section\Type\Prices as Type;
use ShoppingFeed\Manager\Model\Feed\RefreshableProduct;
use ShoppingFeed\Manager\Model\LabelledValueFactory;

/**
 * @method ConfigInterface getConfig()
 */
class Prices extends AbstractAdapter implements PricesInterface
{
    const KEY_BASE_PRICE = 'base_price';
    const KEY_SPECIAL_PRICE = 'special_price';
    const KEY_FINAL_PRICE = 'final_price';
    const KEY_TIER_PRICE = 'tier_price';
    const KEY_SPECIAL_PRICE_FROM_DATE = 'special_price_from_date';
    const KEY_SPECIAL_PRICE_TO_DATE = 'special_price_to_date';
    const KEY_VAT = 'vat';
    const KEY_ECOTAX = 'ecotax';
    const KEY_TAX_RATE = 'tax_rate';

    /**
     * @var CatalogProductResourceFactory
     */
    private $catalogProductResourceFactory;

    /**
     * @var TimezoneInterface
     */
    private $localeDate;

    /**
     * @var CatalogProductResource|null
     */
    private $catalogProductResource = null;

    /**
     * @var TaxCalculationInterface
     */
    private $taxCalculator;

    /**
     * @var AbstractAttributeRenderer
     */
    private $fptAttributeRenderer;

    /**
     * @param StoreManagerInterface $storeManager
     * @param LabelledValueFactory $labelledValueFactory
     * @param AttributeRendererPoolInterface $attributeRendererPool
     * @param CatalogProductResourceFactory $catalogProductResourceFactory
     * @param TimezoneInterface $localeDate
     * @param TaxCalculationInterface $taxCalculator
     * @param AbstractAttributeRenderer $fptAttributeRenderer
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        LabelledValueFactory $labelledValueFactory,
        AttributeRendererPoolInterface $attributeRendererPool,
        CatalogProductResourceFactory $catalogProductResourceFactory,
        TimezoneInterface $localeDate,
        TaxCalculationInterface $taxCalculator,
        AbstractAttributeRenderer $fptAttributeRenderer
    ) {
        $this->catalogProductResourceFactory = $catalogProductResourceFactory;
        $this->localeDate = $localeDate;
        $this->taxCalculator = $taxCalculator;
        $this->fptAttributeRenderer = $fptAttributeRenderer;
        parent::__construct($storeManager, $labelledValueFactory, $attributeRendererPool);
    }

    public function getSectionType()
    {
        return Type::CODE;
    }

    public function requiresLoadedProduct(StoreInterface $store)
    {
        return true;
    }

    public function prepareLoadableProductCollection(StoreInterface $store, ProductCollection $productCollection)
    {
        $productCollection->addAttributeToSelect(
            [
                'price_type',
                'special_price',
                'special_from_date',
                'special_to_date',
            ]
        );

        if ($ecotaxAttribute = $this->getConfig()->getEcotaxAttribute($store)) {
            $productCollection->addAttributeToSelect($ecotaxAttribute->getAttributeCode());
        }

        $productCollection->addPriceData(
            $this->getConfig()->getCustomerGroupId($store),
            $store->getBaseStore()->getWebsiteId()
        );
    }

    public function prepareLoadedProductCollection(StoreInterface $store, ProductCollection $productCollection)
    {
        $productCollection->addTierPriceData();

        if ($ecoTaxAttribute = $this->getConfig()->getEcotaxAttribute($store)) {
            /** @var CatalogProduct $product */
            foreach ($productCollection as $product) {
                try {
                    $ecoTaxAttribute->getBackend()->afterLoad($product);
                } catch (\Exception $e) {
                    // The attribute backend is invalid, not exporting anything is fine then.
                }
            }
        }
    }

    /**
     * @return CatalogProductResource
     */
    private function getCatalogProductResource()
    {
        if (null === $this->catalogProductResource) {
            $this->catalogProductResource = $this->catalogProductResourceFactory->create();
        }

        return $this->catalogProductResource;
    }

    /**
     * @param float $price
     * @param float $taxRate
     * @return float
     */
    private function getPriceTaxAmount($price, $taxRate)
    {
        return ($taxRate > 0) ? ($price - $price / (1 + $taxRate / 100)) : 0.0;
    }

    /**
     * @param float $price
     * @param float $taxRate
     * @return float
     */
    private function applyTaxRateOnPrice($price, $taxRate)
    {
        return ($taxRate > 0) ? ($price + $price * $taxRate / 100) : $price;
    }

    /**
     * @param StoreInterface $store
     * @param CatalogProduct $catalogProduct
     * @param string $attributeCode
     * @return string
     */
    private function getDateValue(StoreInterface $store, CatalogProduct $catalogProduct, $attributeCode)
    {
        if ($attribute = $this->getCatalogProductResource()->getAttribute($attributeCode)) {
            return !empty($dateValue = $this->getCatalogProductAttributeValue($store, $catalogProduct, $attribute))
                ? $dateValue
                : (string) $catalogProduct->getData($attributeCode);
        }

        return '';
    }

    /**
     * @param BundleProductType $productType
     * @param CatalogProduct $product
     * @return float
     */
    private function getBundleProductBasePrice(BundleProductType $productType, CatalogProduct $product)
    {
        // Mirror the computation of the final price for the configured selections,
        // without applying any discount (special price, tier price or catalog price rule).
        $bundlePrice = (float) $product->getPrice();
        $selectionIdsOption = $product->getCustomOption('bundle_selection_ids');
        $selectionIds = $selectionIdsOption ? json_decode((string) $selectionIdsOption->getValue(), true) : [];

        if (empty($selectionIds) || !is_array($selectionIds)) {
            return $bundlePrice;
        }

        $selectionsPrice = 0.0;

        /** @var CatalogProduct $selection */
        foreach ($productType->getSelectionsByIds($selectionIds, $product)->getItems() as $selection) {
            $selectionQtyOption = $product->getCustomOption('selection_qty_' . $selection->getSelectionId());

            if (!$selectionQtyOption || !$selection->isSalable()) {
                continue;
            }

            $selectionPrice = $selection->getSelectionPriceType()
                ? $bundlePrice * ($selection->getSelectionPriceValue() / 100)
                : (float) $selection->getSelectionPriceValue();

            $selectionsPrice += $selectionPrice * $selectionQtyOption->getValue();
        }

        return $bundlePrice + $selectionsPrice;
    }

    /**
     * @param CatalogProduct $product
     * @return float
     */
    private function getProductBasePrice(CatalogProduct $product)
    {
        $productType = $product->getTypeInstance();

        if ($productType instanceof BundleProductType) {
            return $this->getBundleProductBasePrice($productType, $product);
        }

        return (float) $product->getPrice();
    }

    /**
     * @param CatalogProduct $product
     * @return float
     */
    private function getProductSpecialPrice(CatalogProduct $product)
    {
        return (float) $product->getSpecialPrice();
    }

    /**
     * @param CatalogProduct $product
     * @return float
     */
    private function getProductFinalPrice(CatalogProduct $product)
    {
        return (float) $product->getFinalPrice(1);
    }

    /**
     * @param CatalogProduct $product
     * @return float
     */
    private function getProductTierPrice(CatalogProduct $product)
    {
        $tierPrice = $product->getTierPrice(1);

        return is_numeric($tierPrice) ? $tierPrice : 0.0;
    }

    /**
     * @param CatalogProduct $product
     * @param StoreInterface $store
     * @return array
     */
    private function getBasicProductPriceData(CatalogProduct $product, StoreInterface $store)
    {
        $config = $this->getConfig();

        $taxRate = 0.0;
        $isPriceIncludingTax = $store->getScopeConfigValue(TaxConfig::CONFIG_XML_PATH_PRICE_INCLUDES_TAX);

        if ($taxClassId = (int) $product->getData('tax_class_id')) {
            $taxRate = $this->taxCalculator->getCalculatedRate($taxClassId, null, $store->getBaseStoreId());
        }

        $originalWebsiteId = $product->getWebsiteId();
        $originalCustomerGroupId = $product->getCustomerGroupId();

        $product->setWebsiteId($store->getBaseStore()->getWebsiteId());
        $product->setCustomerGroupId($config->getCustomerGroupId($store));

        try {
            $product->unsetData('final_price');
            $product->unsetData('calculated_final_price');

            $basePrice = $this->getProductBasePrice($product);
            $specialPrice = $this->getProductSpecialPrice($product);
            $finalPrice = $this->getProductFinalPrice($product);

            if ($isPriceIncludingTax) {
                $taxAmount = $this->getPriceTaxAmount($finalPrice, $taxRate);
            } else {
                $basePrice = $this->applyTaxRateOnPrice($basePrice, $taxRate);

                // The special price of bundle products is a percentage.
                if (!($product->getTypeInstance() instanceof BundleProductType)) {
                    $specialPrice = $this->applyTaxRateOnPrice($specialPrice, $taxRate);
                }

                $finalPriceExclTax = $finalPrice;
                $finalPrice = $this->applyTaxRateOnPrice($finalPrice, $taxRate);
                $taxAmount = $finalPrice - $finalPriceExclTax;
            }

            $data = [
                self::KEY_BASE_PRICE => round($basePrice, 2),
                self::KEY_SPECIAL_PRICE => ($specialPrice > 0) ? round($specialPrice, 2) : '',
                self::KEY_FINAL_PRICE => round($finalPrice, 2),
                self::KEY_SPECIAL_PRICE_FROM_DATE => $this->getDateValue($store, $product, 'special_from_date'),
                self::KEY_SPECIAL_PRICE_TO_DATE => $this->getDateValue($store, $product, 'special_to_date'),
                self::KEY_VAT => round($taxAmount, 2),
                // Required to discount the children of bundle products the same way as Magento.
                self::KEY_TAX_RATE => (float) $taxRate,
            ];

            if (
                ($ecotaxAttribute = $config->getEcotaxAttribute($store))
                && $this->fptAttributeRenderer->isAppliableToAttribute($ecotaxAttribute)
            ) {
                $ecotaxAmounts = $this->fptAttributeRenderer->getProductAttributeValue(
                    $store,
                    $product,
                    $ecotaxAttribute
                );

                $ecotaxCountry = strtolower($config->getEcotaxCountry($store));

                if (is_array($ecotaxAmounts) && isset($ecotaxAmounts[$ecotaxCountry])) {
                    $data[self::KEY_ECOTAX] = (float) $ecotaxAmounts[$ecotaxCountry];

                    if (!$isPriceIncludingTax && $store->getScopeConfigValue(WeeeConfig::XML_PATH_FPT_TAXABLE)) {
                        $data[self::KEY_ECOTAX] = $this->applyTaxRateOnPrice($data[self::KEY_ECOTAX], $taxRate);
                    }
                }
            }
        } finally {
            $product->setWebsiteId($originalWebsiteId);
            $product->setCustomerGroupId($originalCustomerGroupId);
        }

        return $data;
    }

    /**
     * @param float $priceA
     * @param float $priceB
     * @param string $priceType
     * @return int
     */
    private function compareVariationPricesPriority($priceA, $priceB, $priceType)
    {
        return (ConfigInterface::CONFIGURABLE_PRODUCT_PRICE_TYPE_VARIATIONS_MINIMUM === $priceType)
            ? $priceB <=> $priceA
            : $priceA <=> $priceB;
    }

    /**
     * @param ConfigurableProductType $productType
     * @param CatalogProduct $product
     * @param StoreInterface $store
     * @param string $priceType
     * @return array
     */
    private function getConfigurablePriceData(
        ConfigurableProductType $productType,
        CatalogProduct $product,
        StoreInterface $store,
        $priceType
    ) {
        $variationCollection = $productType->getUsedProductCollection($product);
        $variationCollection->addStoreFilter($store->getBaseStoreId());

        $this->prepareLoadableProductCollection($store, $variationCollection);

        $priceData = [];
        $currentFinalPrice = 0;

        /** @var CatalogProduct $variation */
        foreach ($variationCollection as $variation) {
            $variationPriceData = $this->getBasicProductPriceData($variation, $store);
            $variationFinalPrice = $variationPriceData[self::KEY_FINAL_PRICE] ?? 0;

            if (!empty($variationFinalPrice)) {
                if (
                    empty($priceData)
                    || (0 < $this->compareVariationPricesPriority($variationFinalPrice, $currentFinalPrice, $priceType))
                ) {
                    $priceData = $variationPriceData;
                    $currentFinalPrice = $variationFinalPrice;
                }
            }
        }

        return $priceData;
    }

    /**
     * @param BundleProductType $productType
     * @param CatalogProduct $product
     * @param StoreInterface $store
     * @return array
     */
    private function getBundleFixedPriceData(
        BundleProductType $productType,
        CatalogProduct $product,
        StoreInterface $store
    ) {
        // Prepare the product so that each default selection is taken into account using its default quantity.
        $allOptionIds = $productType->getOptionsIds($product);
        $defaultSelectionIds = [];
        $selectionCollection = $productType->getSelectionsCollection($allOptionIds, $product);

        try {
            /** @var BundleProductSelection $selection */
            foreach ($selectionCollection as $selection) {
                if ($selection->getIsDefault()) {
                    $selectionId = (int) $selection->getSelectionId();
                    $defaultSelectionIds[] = $selectionId;
                    $product->addCustomOption('selection_qty_' . $selectionId, $selection->getSelectionQty());
                }
            }

            $product->addCustomOption('bundle_selection_ids', json_encode($defaultSelectionIds));

            $priceData = $this->getBasicProductPriceData($product, $store);
        } finally {
            // Clean up the product to avoid any inconsistent behavior in other sections.
            $product->setCustomOptions([]);
        }

        return $priceData;
    }

    /**
     * @param CatalogProduct $product
     * @param StoreInterface $store
     */
    private function getBundleDynamicPriceData(CatalogProduct $product, StoreInterface $store)
    {
        $priceData = [
            self::KEY_SPECIAL_PRICE_FROM_DATE => $this->getDateValue($store, $product, 'special_from_date'),
            self::KEY_SPECIAL_PRICE_TO_DATE => $this->getDateValue($store, $product, 'special_to_date'),
        ];

        // Tier prices depend on the customer group, which would otherwise default to the one from the session.
        $originalCustomerGroupId = $product->getCustomerGroupId();
        $product->setCustomerGroupId($this->getConfig()->getCustomerGroupId($store));

        try {
            $tierPrice = $this->getProductTierPrice($product);
        } finally {
            $product->setCustomerGroupId($originalCustomerGroupId);
        }

        if ($tierPrice > 0.0) {
            $priceData[self::KEY_TIER_PRICE] = $tierPrice;
        }

        $specialPrice = $this->getProductSpecialPrice($product);

        if (
            ($specialPrice > 0.0)
            && $this->localeDate->isScopeDateInInterval(
                $store->getBaseStore(),
                $product->getSpecialFromDate(),
                $product->getSpecialToDate()
            )
        ) {
            $priceData[self::KEY_SPECIAL_PRICE] = $specialPrice;
        }

        return $priceData;
    }

    public function getProductData(StoreInterface $store, RefreshableProduct $product)
    {
        $productData = [];

        $catalogProduct = $product->getCatalogProduct();
        $productType = $catalogProduct->getTypeInstance();

        if ($productType instanceof ConfigurableProductType) {
            $priceType = $this->getConfig()->getConfigurableProductPriceType($store);

            if (ConfigInterface::CONFIGURABLE_PRODUCT_PRICE_TYPE_NONE !== $priceType) {
                $productData = $this->getConfigurablePriceData($productType, $catalogProduct, $store, $priceType);
            }
        } elseif ($productType instanceof BundleProductType) {
            if ((int) $catalogProduct->getPriceType() === BundleProductPrice::PRICE_TYPE_FIXED) {
                $productData = $this->getBundleFixedPriceData($productType, $catalogProduct, $store);
            } else {
                $productData = $this->getBundleDynamicPriceData($catalogProduct, $store);
            }
        } else {
            $productData = $this->getBasicProductPriceData($catalogProduct, $store);
        }

        return $productData;
    }

    public function adaptBundleProductData(
        StoreInterface $store,
        array $bundleData,
        array $childrenData,
        array $childrenQuantities
    ) {
        if (isset($bundleData[self::KEY_FINAL_PRICE])) {
            // Fixed prices are handled at refresh time.
            return $bundleData;
        }

        // The special price and tier price of bundle products are percentages.
        $specialPercentage = (float) ($bundleData[self::KEY_SPECIAL_PRICE] ?? 0.0);
        $tierPercentage = (float) ($bundleData[self::KEY_TIER_PRICE] ?? 0.0);
        $isDiscounted = ($specialPercentage > 0.0) || ($tierPercentage > 0.0);

        $baseLines = [];
        $finalLines = [];
        $ecotaxAmount = 0.0;

        foreach ($childrenData as $key => $childData) {
            $bundledQuantity = max(1, $childrenQuantities[$key] ?? 0);
            $taxRate = $this->getBundleChildTaxRate($childData);

            $baseUnitPrice = $this->getBundleChildCatalogPrice(
                $store,
                (float) ($childData[self::KEY_BASE_PRICE] ?? 0.0),
                $taxRate
            );

            $finalUnitPrice = $this->getBundleChildCatalogPrice(
                $store,
                (float) ($childData[self::KEY_FINAL_PRICE] ?? 0.0),
                $taxRate
            );

            if ($isDiscounted) {
                $finalUnitPrice = $this->getBundleChildDiscountedPrice(
                    $finalUnitPrice,
                    $specialPercentage,
                    $tierPercentage
                );
            }

            $baseLines[] = [ $baseUnitPrice, $bundledQuantity, $taxRate ];
            $finalLines[] = [ $finalUnitPrice, $bundledQuantity, $taxRate ];
            $ecotaxAmount += ((float) ($childData[self::KEY_ECOTAX] ?? 0.0)) * $bundledQuantity;
        }

        list ($basePrice) = $this->getBundleChildrenTotalPrices($store, $baseLines);
        list ($finalPrice, $taxAmount) = $this->getBundleChildrenTotalPrices($store, $finalLines);

        $bundleData[self::KEY_BASE_PRICE] = $basePrice;
        $bundleData[self::KEY_FINAL_PRICE] = $finalPrice;
        $bundleData[self::KEY_VAT] = $taxAmount;
        $bundleData[self::KEY_ECOTAX] = round($ecotaxAmount, 2);

        return $bundleData;
    }

    /**
     * @param array $childData
     * @return float
     */
    private function getBundleChildTaxRate(array $childData)
    {
        if (isset($childData[self::KEY_TAX_RATE])) {
            return (float) $childData[self::KEY_TAX_RATE];
        }

        $finalPrice = (float) ($childData[self::KEY_FINAL_PRICE] ?? 0.0);
        $taxAmount = (float) ($childData[self::KEY_VAT] ?? 0.0);

        // The tax rate is missing from the data refreshed by previous versions: approximate it.
        return ($finalPrice > $taxAmount) ? ($taxAmount / ($finalPrice - $taxAmount) * 100) : 0.0;
    }

    /**
     * Returns the unit price of a bundle child as it was entered in the catalog, including or excluding tax.
     * This is the price that Magento discounts and rounds.
     *
     * @param StoreInterface $store
     * @param float $price The unit price of the child, including tax.
     * @param float $taxRate
     * @return float
     */
    private function getBundleChildCatalogPrice(StoreInterface $store, $price, $taxRate)
    {
        if ($store->getScopeConfigValue(TaxConfig::CONFIG_XML_PATH_PRICE_INCLUDES_TAX)) {
            return $price;
        }

        // This gives back the price excluding tax that was entered, as long as it had at most 2 decimals.
        return round($price / (1 + $taxRate / 100), 2);
    }

    /**
     * Applies the discount of a bundle product on the unit price of one of its children, rounded as Magento does.
     *
     * @see \Magento\Bundle\Model\Product\Price::getLowestPrice()
     * @param float $price
     * @param float $specialPercentage
     * @param float $tierPercentage
     * @return float
     */
    private function getBundleChildDiscountedPrice($price, $specialPercentage, $tierPercentage)
    {
        // As in Magento, the special price and tier price discounts do not stack: only the best one applies.
        $discountedPrice = $price;

        if ($specialPercentage > 0.0) {
            $discountedPrice = min($discountedPrice, $price * ($specialPercentage / 100));
        }

        if ($tierPercentage > 0.0) {
            $discountedPrice = min($discountedPrice, $price - $price * ($tierPercentage / 100));
        }

        // As in Magento, prices can not go below zero.
        return round(max(0.0, $discountedPrice), 2);
    }

    /**
     * @see \Magento\Tax\Model\Calculation::calcTaxAmount()
     * @param float $price
     * @param float $taxRate
     * @param bool $isPriceIncludingTax
     * @return float The unrounded tax amount.
     */
    private function getExactTaxAmount($price, $taxRate, $isPriceIncludingTax)
    {
        $taxRate = $taxRate / 100;

        return $isPriceIncludingTax ? ($price * (1 - 1 / (1 + $taxRate))) : ($price * $taxRate);
    }

    /**
     * @see \Magento\Tax\Model\Calculation\AbstractCalculator::deltaRound()
     * @param float $taxAmount
     * @return float
     */
    private function roundTaxAmount($taxAmount)
    {
        // As in Magento, a tiny initial delta makes the rounding of half cents deterministic.
        return ($taxAmount != 0.0) ? round($taxAmount + 0.000001, 2) : 0.0;
    }

    /**
     * Returns the price (including tax) and tax amount of a set of bundled children, as computed in a Magento cart.
     * Unit prices are given as they are entered in the catalog (including or excluding tax), and already rounded.
     * As in Magento, the tax amount is rounded per unit, per row or for the whole bundle, depending on the store.
     *
     * @see \Magento\Tax\Model\Calculation\UnitBaseCalculator
     * @see \Magento\Tax\Model\Calculation\RowBaseCalculator
     * @see \Magento\Tax\Model\Calculation\TotalBaseCalculator
     * @param StoreInterface $store
     * @param array $lines A list of [ unit price, quantity, tax rate ].
     * @return float[]
     */
    private function getBundleChildrenTotalPrices(StoreInterface $store, array $lines)
    {
        $isPriceIncludingTax = (bool) $store->getScopeConfigValue(TaxConfig::CONFIG_XML_PATH_PRICE_INCLUDES_TAX);
        $algorithm = $store->getScopeConfigValue(TaxConfig::XML_PATH_ALGORITHM);

        $rowTotal = 0.0;
        $taxAmount = 0.0;
        $exactTaxAmounts = [];

        foreach ($lines as list($unitPrice, $quantity, $taxRate)) {
            $lineTotal = $unitPrice * $quantity;
            $rowTotal += $lineTotal;

            if (TaxCalculation::CALC_UNIT_BASE === $algorithm) {
                $unitTaxAmount = $this->getExactTaxAmount($unitPrice, $taxRate, $isPriceIncludingTax);
                $taxAmount += $this->roundTaxAmount($unitTaxAmount) * $quantity;
            } elseif (TaxCalculation::CALC_ROW_BASE === $algorithm) {
                $taxAmount += $this->roundTaxAmount(
                    $this->getExactTaxAmount($lineTotal, $taxRate, $isPriceIncludingTax)
                );
            } else {
                // Rounding deltas are carried over from one row to the next (per tax rate),
                // so that the tax amount of the bundle is the rounded sum of the exact tax amounts.
                $rateKey = (string) $taxRate;
                $exactTaxAmounts[$rateKey] = ($exactTaxAmounts[$rateKey] ?? 0.0)
                    + $this->getExactTaxAmount($lineTotal, $taxRate, $isPriceIncludingTax);
            }
        }

        foreach ($exactTaxAmounts as $exactTaxAmount) {
            $taxAmount += $this->roundTaxAmount($exactTaxAmount);
        }

        $totalPrice = $isPriceIncludingTax ? $rowTotal : ($rowTotal + $taxAmount);

        // The amounts are sums of rounded amounts: only floating-point errors remain to be removed.
        return [ round($totalPrice, 2), round($taxAmount, 2) ];
    }

    public function exportBaseProductData(
        StoreInterface $store,
        array $productData,
        AbstractExportedProduct $exportedProduct
    ) {
        $discountExportMode = $this->getConfig()->getDiscountExportMode($store);

        $hasBasePrice = isset($productData[self::KEY_BASE_PRICE]);
        $hasFinalPrice = isset($productData[self::KEY_FINAL_PRICE]);

        $isDiscounted = $hasBasePrice
            && $hasFinalPrice
            && ($productData[self::KEY_FINAL_PRICE] < $productData[self::KEY_BASE_PRICE]);

        if (ConfigInterface::DISCOUNT_EXPORT_MODE_PRICE_ATTRIBUTE === $discountExportMode) {
            if ($isDiscounted || $hasFinalPrice) {
                $exportedProduct->setPrice($productData[self::KEY_FINAL_PRICE]);
            } elseif ($hasBasePrice) {
                $exportedProduct->setPrice($productData[self::KEY_BASE_PRICE]);
            }
        } else {
            if ($isDiscounted) {
                $exportedProduct->setPrice($productData[self::KEY_BASE_PRICE]);
                $exportedProduct->addDiscount($productData[self::KEY_FINAL_PRICE]);
            } elseif ($hasFinalPrice) {
                $exportedProduct->setPrice($productData[self::KEY_FINAL_PRICE]);
            } elseif ($hasBasePrice) {
                $exportedProduct->setPrice($productData[self::KEY_BASE_PRICE]);
            }
        }

        if (isset($productData[self::KEY_VAT])) {
            if (is_callable([ $exportedProduct, 'setVat' ])) {
                $exportedProduct->setVat($productData[self::KEY_VAT]);
            } else {
                $exportedProduct->setAttribute('vat', $productData[self::KEY_VAT]);
            }
        }

        if (isset($productData[self::KEY_ECOTAX])) {
            if (is_callable([ $exportedProduct, 'setEcotax' ])) {
                $exportedProduct->setEcotax($productData[self::KEY_ECOTAX]);
            } else {
                $exportedProduct->setAttribute('ecotax', $productData[self::KEY_ECOTAX]);
            }
        }

        if ($hasBasePrice) {
            $exportedProduct->setAttribute('price_before_discount', $productData[self::KEY_BASE_PRICE]);
        }
    }

    public function describeProductData(StoreInterface $store, array $productData)
    {
        return $this->describeRawProductData(
            [
                self::KEY_BASE_PRICE => __('Base'),
                self::KEY_SPECIAL_PRICE => __('Special'),
                self::KEY_FINAL_PRICE => __('Final'),
                self::KEY_VAT => __('Tax Amount'),
                self::KEY_ECOTAX => __('Eco-tax Amount'),
            ],
            $productData
        );
    }
}
