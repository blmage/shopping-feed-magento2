<?php

namespace ShoppingFeed\Manager\Observer;

use Magento\AsynchronousOperations\Api\Data\OperationInterfaceFactory as AsyncOperationInterfaceFactory;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Catalog\Helper\Product\Edit\Action\Attribute as AttributeActionHelper;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Bulk\BulkManagementInterface;
use Magento\Framework\Bulk\OperationInterface;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\Serialize\SerializerInterface;
use ShoppingFeed\Manager\Block\Adminhtml\Catalog\Product\Edit\Action\Attribute\Tab\FeedAttributes as FeedAttributesTab;
use ShoppingFeed\Manager\Model\Feed\Product\Backend\Consumer;

class ScheduleProductsAttributesUpdateObserver implements ObserverInterface
{
    /**
     * @var RequestInterface
     */
    private $request;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var IdentityGeneratorInterface
     */
    private $identityService;

    /**
     * @var UserContextInterface
     */
    private $userContext;

    /**
     * @var AsyncOperationInterfaceFactory
     */
    private $operationFactory;

    /**
     * @var BulkManagementInterface
     */
    private $bulkManagement;

    /**
     * @var MessageManagerInterface
     */
    private $messageManager;

    /**
     * @var AttributeActionHelper
     */
    private $attributeActionHelper;

    /**
     * @var SaveProductsAttributesObserver
     */
    private $saveProductsAttributesObserver;

    /**
     * @var int
     */
    private $bulkSize;

    /**
     * @param RequestInterface $request
     * @param IdentityGeneratorInterface $identityService
     * @param UserContextInterface $userContext
     * @param AsyncOperationInterfaceFactory $operationFactory
     * @param BulkManagementInterface $bulkManagement
     * @param AttributeActionHelper $attributeActionHelper
     * @param int $bulkSize
     */
    public function __construct(
        RequestInterface $request,
        SerializerInterface $serializer,
        IdentityGeneratorInterface $identityService,
        UserContextInterface $userContext,
        AsyncOperationInterfaceFactory $operationFactory,
        BulkManagementInterface $bulkManagement,
        MessageManagerInterface $messageManager,
        AttributeActionHelper $attributeActionHelper,
        SaveProductsAttributesObserver $saveProductsAttributesObserver,
        $bulkSize = 100
    ) {
        $this->request = $request;
        $this->serializer = $serializer;
        $this->identityService = $identityService;
        $this->userContext = $userContext;
        $this->operationFactory = $operationFactory;
        $this->bulkManagement = $bulkManagement;
        $this->messageManager = $messageManager;
        $this->attributeActionHelper = $attributeActionHelper;
        $this->saveProductsAttributesObserver = $saveProductsAttributesObserver;
        $this->bulkSize = (int) $bulkSize;
    }

    public function execute(Observer $observer)
    {
        $params = $this->request->getParams();
        $productIds = $this->attributeActionHelper->getProductIds();

        if (
            isset($params[FeedAttributesTab::DATA_SCOPE])
            && is_array($params[FeedAttributesTab::DATA_SCOPE])
            && !empty($productIds)
            && !$this->saveProductsAttributesObserver->hasSavedProductAttributes()
        ) {
            $productIdsChunks = array_chunk($productIds, $this->bulkSize);
            $bulkUuid = $this->identityService->generateId();
            $bulkDescription = 'Update Shopping Feed attributes for ' . count($productIds) . ' selected products';
            $operations = [];

            foreach ($params[FeedAttributesTab::DATA_SCOPE] as $storeId => $attributes) {
                $isSelectedValue = $attributes[FeedAttributesTab::FIELD_IS_SELECTED] ?? null;
                $selectedCategoryIdValue = $attributes[FeedAttributesTab::FIELD_SELECTED_CATEGORY_ID] ?? null;

                foreach ($productIdsChunks as $productIdsChunk) {
                    $dataToEncode = [
                        'meta_information' => 'Update product Shopping Feed attributes',
                        Consumer::DATA_KEY_PRODUCT_IDS => $productIdsChunk,
                        Consumer::DATA_KEY_STORE_ID => $storeId,
                        Consumer::DATA_KEY_IS_SELECTED => $isSelectedValue,
                        Consumer::DATA_KEY_SELECTED_CATEGORY_ID => $selectedCategoryIdValue,
                    ];

                    $data = [
                        'data' => [
                            'bulk_uuid' => $bulkUuid,
                            'topic_name' => Consumer::QUEUE_NAME,
                            'serialized_data' => $this->serializer->serialize($dataToEncode),
                            'status' => OperationInterface::STATUS_TYPE_OPEN,
                        ],
                    ];

                    $operations[] = $this->operationFactory->create($data);
                }
            }

            if (!empty($operations)) {
                $result = $this->bulkManagement->scheduleBulk(
                    $bulkUuid,
                    $operations,
                    $bulkDescription,
                    $this->userContext->getUserId()
                );

                if (!$result) {
                    $this->messageManager->addErrorMessage(
                        __('Something went wrong while scheduling the Shopping Feed attributes for update.')
                    );
                }
            }
        }
    }
}
