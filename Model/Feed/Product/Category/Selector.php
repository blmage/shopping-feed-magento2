<?php

namespace ShoppingFeed\Manager\Model\Feed\Product\Category;

use Magento\Catalog\Model\Category as CatalogCategory;
use Magento\Catalog\Model\Product as CatalogProduct;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface as BaseStoreManagerInterface;
use ShoppingFeed\Manager\Api\Data\Account\StoreInterface;
use ShoppingFeed\Manager\Model\Feed\Product\Category as FeedCategory;
use ShoppingFeed\Manager\Model\Feed\Product\CategoryFactory as FeedCategoryFactory;

class Selector implements SelectorInterface
{
    /**
     * @var BaseStoreManagerInterface
     */
    private $baseStoreManager;

    /**
     * @var UrlInterface
     */
    private $frontendUrlBuilder;

    /**
     * @var CategoryCollectionFactory
     */
    private $categoryCollectionFactory;

    /**
     * @var FeedCategoryFactory
     */
    private $feedCategoryFactory;

    /**
     * @var array[]
     */
    private $storeCategoryTree = [];

    /**
     * @var FeedCategory[][]
     */
    private $storeCategoryList = [];

    /**
     * @var int[]
     */
    private $storeFullSelectionIds = [];

    /**
     * @param BaseStoreManagerInterface $baseStoreManager
     * @param UrlInterface $frontendUrlBuilder
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param FeedCategoryFactory $feedCategoryFactory
     */
    public function __construct(
        BaseStoreManagerInterface $baseStoreManager,
        UrlInterface $frontendUrlBuilder,
        CategoryCollectionFactory $categoryCollectionFactory,
        FeedCategoryFactory $feedCategoryFactory
    ) {
        $this->baseStoreManager = $baseStoreManager;
        $this->frontendUrlBuilder = $frontendUrlBuilder;
        $this->categoryCollectionFactory = $categoryCollectionFactory;
        $this->feedCategoryFactory = $feedCategoryFactory;
    }

    /**
     * @param CatalogCategory $category
     */
    private function initializeCategoryUrl(CatalogCategory $category)
    {
        if ($category->hasData('request_path') && ('' !== $category->getRequestPath())) {
            $url = $this->frontendUrlBuilder->getDirectUrl($category->getRequestPath());
        } else {
            $url = $this->frontendUrlBuilder->getUrl(
                'catalog/category/view',
                [
                    'id' => (int) $category->getId(),
                    's' => $category->getUrlKey()
                        ? $category->getUrlKey()
                        : $category->formatUrlKey($category->getName()),
                ]
            );
        }

        $category->setData('url', $url);
    }

    /**
     * @param int[] $rootIds
     * @param int[] $parentChildIds
     * @return array
     */
    private function getCategoryIdTree(array $rootIds, array $parentChildIds)
    {
        $tree = [];

        foreach ($rootIds as $rootId) {
            $tree[] = $rootId;

            if (isset($parentChildIds[$rootId]) && is_array($parentChildIds[$rootId])) {
                $tree[] = $this->getCategoryIdTree($parentChildIds[$rootId], $parentChildIds);
            }
        }

        return $tree;
    }

    /**
     * @param StoreInterface $store
     * @return FeedCategory[]
     * @throws LocalizedException
     */
    private function getStoreCategoryList(StoreInterface $store)
    {
        $storeId = $store->getBaseStoreId();

        if (!isset($this->storeCategoryList[$storeId])) {
            $this->storeCategoryList[$storeId] = [];
            $baseStoreGroup = $this->baseStoreManager->getGroup($store->getBaseStore()->getStoreGroupId());
            $rootCategoryId = $baseStoreGroup->getRootCategoryId();

            $categoryCollection = $this->categoryCollectionFactory->create();

            $categoryCollection->setStoreId($storeId);
            $categoryCollection->addPathFilter('^' . CatalogCategory::TREE_ROOT_ID . '/' . $rootCategoryId);
            $categoryCollection->addNameToResult();
            $categoryCollection->addUrlRewriteToResult();
            $categoryCollection->addAttributeToSelect('is_active');
            $categoryCollection->addAttributeToSort('position', 'asc');

            $this->frontendUrlBuilder->setScope($store->getBaseStoreId());

            $parentChildIds = [];

            /** @var CatalogCategory $category */
            foreach ($categoryCollection as $category) {
                // Force the category URL as the URL instance does not use the emulated scope (if any).
                $this->initializeCategoryUrl($category);
                $parentChildIds[$category->getParentId()][] = (int) $categoryId = $category->getId();
            }

            $idTree = $this->getCategoryIdTree(
                $parentChildIds[$rootCategoryId] ?? [],
                $parentChildIds
            );

            $sortedIds = [];

            array_walk_recursive(
                $idTree,
                function ($categoryId) use (&$sortedIds) {
                    $sortedIds[] = $categoryId;
                }
            );

            foreach ($sortedIds as $index => $categoryId) {
                /** @var CatalogCategory $category */
                if ($category = $categoryCollection->getItemById($categoryId)) {
                    $feedCategory = $this->feedCategoryFactory->create([ 'catalogCategory' => $category ]);
                    $feedCategory->setGlobalPosition($index + 1);
                    $this->storeCategoryList[$storeId][$category->getId()] = $feedCategory;
                }
            }
        }

        return $this->storeCategoryList[$storeId];
    }

    /**
     * @param StoreInterface $store
     * @return array
     * @throws LocalizedException
     */
    public function getStoreCategoryTree(StoreInterface $store)
    {
        $storeId = $store->getBaseStoreId();

        if (!isset($this->storeCategoryTree[$storeId])) {
            $baseStoreGroup = $this->baseStoreManager->getGroup($store->getBaseStore()->getStoreGroupId());
            $rootCategoryId = $baseStoreGroup->getRootCategoryId();

            $categoryList = $this->getStoreCategoryList($store);
            $categoryTree = [];

            foreach ($categoryList as $category) {
                $categoryId = $category->getId();
                $parentId = $category->getParentId();

                if (!isset($categoryTree[$categoryId])) {
                    $categoryTree[$categoryId] = [ 'value' => $categoryId ];
                }

                if (!isset($categoryTree[$parentId])) {
                    $categoryTree[$parentId] = [ 'value' => $parentId ];
                }

                $categoryTree[$categoryId]['label'] = $category->getName();
                $categoryTree[$categoryId]['is_active'] = $category->isActive();
                $categoryTree[$parentId]['optgroup'][] = &$categoryTree[$categoryId];
            }

            $this->storeCategoryTree[$storeId] = $categoryTree[$rootCategoryId]['optgroup'] ?? [];
        }

        return $this->storeCategoryTree[$storeId];
    }

    /**
     * @param FeedCategory $category
     * @param FeedCategory[] $categories
     * @return FeedCategory[]
     */
    protected function getCategoryPath(FeedCategory $category, array $categories)
    {
        $categoryPath = [ $category ];
        $parentLevel = $category->getLevel() - 1;
        $parentId = $category->getParentId();

        while ($parentId && ($parentLevel >= 2) && isset($categories[$parentId])) {
            $categoryPath[] = $categories[$parentId];
            $parentId = $categories[$parentId]->getParentId();
            $parentLevel--;
        }

        return $categoryPath;
    }

    /**
     * @param FeedCategory $category
     * @param int[] $selectionIds
     * @param string $selectionMode
     * @param int $maximumLevel
     * @return bool
     */
    private function isSelectableCategory(
        FeedCategory $category,
        array $selectionIds,
        $selectionMode,
        $maximumLevel = PHP_INT_MAX
    ) {
        if (!$category->isActive() || ($category->getLevel() > $maximumLevel)) {
            return false;
        }

        $isSelected = in_array($category->getId(), $selectionIds, true);

        return ($selectionMode === self::SELECTION_MODE_INCLUDE) ? $isSelected : !$isSelected;
    }

    /**
     * @param StoreInterface $store
     * @param int[] $selectionIds
     * @param bool $includeSubCategoriesInSelection
     * @return int[]
     * @throws LocalizedException
     */
    private function getFullSelectionCategoryIds(
        StoreInterface $store,
        array $selectionIds,
        $includeSubCategoriesInSelection
    ) {
        $storeId = $store->getId();
        $categories = $this->getStoreCategoryList($store);

        if ($includeSubCategoriesInSelection) {
            if (!isset($this->storeFullSelectionIds[$storeId])) {
                $this->storeFullSelectionIds[$storeId] = $selectionIds;

                foreach ($categories as $categoryId => $category) {
                    if (
                        !in_array($categoryId, $selectionIds, true)
                        && !empty(array_intersect($category->getPathIds(), $selectionIds))
                    ) {
                        $this->storeFullSelectionIds[$storeId][] = $categoryId;
                    }
                }
            }

            $selectionIds = $this->storeFullSelectionIds[$storeId];
        }

        return $selectionIds;
    }

    public function getStoreSelectableCategoryIds(
        StoreInterface $store,
        array $selectionIds,
        $includeSubCategoriesInSelection,
        $selectionMode,
        $maximumLevel = PHP_INT_MAX
    ) {
        $storeCategories = $this->getStoreCategoryList($store);

        $selectionIds = $this->getFullSelectionCategoryIds(
            $store,
            $selectionIds,
            $includeSubCategoriesInSelection
        );

        $selectableCategoryIds = [];

        foreach ($storeCategories as $category) {
            if ($this->isSelectableCategory($category, $selectionIds, $selectionMode, $maximumLevel)) {
                $selectableCategoryIds[] = $category->getId();
            }
        }

        return $selectableCategoryIds;
    }

    public function getCatalogProductCategoryPath(
        CatalogProduct $product,
        StoreInterface $store,
        $preselectedCategoryId,
        array $selectionIds,
        $includeSubCategoriesInSelection,
        $selectionMode,
        $maximumLevel = PHP_INT_MAX,
        $levelWeightMultiplier = 1,
        $useParentCategories = false,
        $includableParentCount = 1,
        $minimumParentLevel = 1,
        $parentWeightMultiplier = 1,
        $tieBreakingSelection = self::TIE_BREAKING_SELECTION_UNDETERMINED
    ) {
        $categories = $this->getStoreCategoryList($store);
        $categoryIds = $product->getCategoryIds();
        $selectedCategoryId = null;

        $selectionIds = $this->getFullSelectionCategoryIds(
            $store,
            $selectionIds,
            $includeSubCategoriesInSelection
        );

        if (
            !empty($preselectedCategoryId)
            && isset($categories[$preselectedCategoryId])
            && $this->isSelectableCategory($categories[$preselectedCategoryId], $selectionIds, $selectionMode)
        ) {
            $selectedCategoryId = $preselectedCategoryId;
        } else {
            $categoryWeights = [];

            foreach ($categoryIds as $categoryId) {
                if (
                    isset($categories[$categoryId])
                    && $this->isSelectableCategory(
                        $categories[$categoryId],
                        $selectionIds,
                        $selectionMode,
                        $maximumLevel
                    )
                ) {
                    $categoryWeights[$categoryId] = $categories[$categoryId]->getLevel() * $levelWeightMultiplier;
                }
            }

            if ($useParentCategories) {
                foreach ($categoryIds as $categoryId) {
                    if (isset($categories[$categoryId])) {
                        $parentLevel = $categories[$categoryId]->getLevel() - 1;
                        $parentCount = 0;
                        $parentId = $categories[$categoryId]->getParentId();

                        while ($parentId
                            && isset($categories[$parentId])
                            && ($parentLevel-- >= $minimumParentLevel)
                            && (++$parentCount <= $includableParentCount)
                        ) {
                            if (
                                !isset($categoryWeights[$parentId])
                                && $this->isSelectableCategory(
                                    $categories[$parentId],
                                    $selectionIds,
                                    $selectionMode,
                                    $maximumLevel
                                )
                            ) {
                                $categoryWeights[$parentId] = $parentLevel
                                    * $levelWeightMultiplier
                                    * $parentWeightMultiplier;
                            }

                            $parentId = $categories[$parentId]->getParentId();
                        }
                    }
                }
            }

            if (empty($categoryWeights)) {
                return null;
            }

            $candidateIds = array_keys($categoryWeights, max($categoryWeights));

            if (self::TIE_BREAKING_SELECTION_UNDETERMINED === $tieBreakingSelection) {
                arsort($categoryWeights, SORT_NUMERIC);
                reset($categoryWeights);
                $selectedCategoryId = key($categoryWeights);
            } else {
                $candidatePositions = [];

                foreach ($candidateIds as $candidateId) {
                    $candidatePositions[$candidateId] = $categories[$candidateId]->getGlobalPosition();
                }

                if (self::TIE_BREAKING_SELECTION_FIRST_IN_TREE === $tieBreakingSelection) {
                    asort($candidatePositions);
                } else {
                    arsort($candidatePositions);
                }

                reset($candidatePositions);
                $selectedCategoryId = key($candidatePositions);
            }
        }

        return $this->getCategoryPath($categories[$selectedCategoryId], $categories);
    }
}
