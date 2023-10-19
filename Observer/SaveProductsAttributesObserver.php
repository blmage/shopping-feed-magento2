<?php

namespace ShoppingFeed\Manager\Observer;

use Magento\Catalog\Helper\Product\Edit\Action\Attribute as AttributeActionHelper;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use ShoppingFeed\Manager\Block\Adminhtml\Catalog\Product\Edit\Action\Attribute\Tab\FeedAttributes as FeedAttributesTab;
use ShoppingFeed\Manager\Model\ResourceModel\Feed\ProductFactory as FeedProductResourceFactory;

class SaveProductsAttributesObserver implements ObserverInterface
{
    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var FeedProductResourceFactory
     */
    private $feedProductResourceFactory;

    /**
     * @var AttributeActionHelper
     */
    private $attributeActionHelper;

    /**
     * @var bool
     */
    private $hasSavedProductAttributes = false;

    /**
     * @param RequestInterface $request
     * @param FeedProductResourceFactory $feedProductResourceFactory
     * @param AttributeActionHelper $attributeActionHelper
     */
    public function __construct(
        RequestInterface $request,
        FeedProductResourceFactory $feedProductResourceFactory,
        AttributeActionHelper $attributeActionHelper
    ) {
        $this->request = $request;
        $this->feedProductResourceFactory = $feedProductResourceFactory;
        $this->attributeActionHelper = $attributeActionHelper;
    }

    public function execute(Observer $observer)
    {
        $params = $this->request->getParams();
        $productIds = $this->attributeActionHelper->getProductIds();

        if (
            isset($params[FeedAttributesTab::DATA_SCOPE])
            && is_array($params[FeedAttributesTab::DATA_SCOPE])
            && !empty($productIds)
        ) {
            $this->hasSavedProductAttributes = true;
            $feedProductResource = $this->feedProductResourceFactory->create();

            foreach ($params[FeedAttributesTab::DATA_SCOPE] as $storeId => $storeFeedAttributes) {
                $feedProductResource->updateProductFeedAttributes(
                    $productIds,
                    (int) $storeId,
                    $storeFeedAttributes[FeedAttributesTab::FIELD_IS_SELECTED] ?? null,
                    $storeFeedAttributes[FeedAttributesTab::FIELD_SELECTED_CATEGORY_ID] ?? null
                );
            }
        }
    }

    public function hasSavedProductAttributes()
    {
        return $this->hasSavedProductAttributes;
    }
}
