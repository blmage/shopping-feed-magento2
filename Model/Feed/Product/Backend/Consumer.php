<?php

namespace ShoppingFeed\Manager\Model\Feed\Product\Backend;

use Magento\AsynchronousOperations\Api\Data\OperationInterface as AsyncOperationInterface;
use Magento\Framework\DB\Adapter\ConnectionException as DbConnectionException;
use Magento\Framework\DB\Adapter\DeadlockException as DbDeadlockException;
use Magento\Framework\DB\Adapter\LockWaitException as DbLockWaitException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\EntityManager\EntityManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Exception\TemporaryStateExceptionInterface;
use Magento\Framework\Bulk\OperationInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Psr\Log\LoggerInterface;
use ShoppingFeed\Manager\Model\ResourceModel\Feed\ProductFactory as FeedProductResourceFactory;

class Consumer
{
    const QUEUE_NAME = 'sfm_product_action_shoppingfeed_attribute.update';

    const DATA_KEY_STORE_ID = 'store_id';
    const DATA_KEY_PRODUCT_IDS = 'product_ids';
    const DATA_KEY_IS_SELECTED = 'is_selected';
    const DATA_KEY_SELECTED_CATEGORY_ID = 'selected_category_id';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @var FeedProductResourceFactory
     */
    private $feedProductResourceFactory;

    /**
     * @param LoggerInterface $logger
     * @param SerializerInterface $serializer
     * @param EntityManager $entityManager
     * @param FeedProductResourceFactory $feedProductResourceFactory
     */
    public function __construct(
        LoggerInterface $logger,
        SerializerInterface $serializer,
        EntityManager $entityManager,
        FeedProductResourceFactory $feedProductResourceFactory
    ) {
        $this->logger = $logger;
        $this->serializer = $serializer;
        $this->entityManager = $entityManager;
        $this->feedProductResourceFactory = $feedProductResourceFactory;
    }

    public function process(AsyncOperationInterface $operation)
    {
        try {
            $status = OperationInterface::STATUS_TYPE_COMPLETE;
            $errorCode = null;
            $message = null;

            $serializedData = $operation->getSerializedData();
            $data = $this->serializer->unserialize($serializedData);

            if (
                is_array($data[static::DATA_KEY_PRODUCT_IDS])
                && !empty($data[static::DATA_KEY_PRODUCT_IDS])
                && !empty($data[static::DATA_KEY_STORE_ID])
                && (isset($data[static::DATA_KEY_IS_SELECTED]) || isset($data[static::DATA_KEY_SELECTED_CATEGORY_ID]))
            ) {
                $feedProductResource = $this->feedProductResourceFactory->create();

                $feedProductResource->updateProductFeedAttributes(
                    $data[static::DATA_KEY_PRODUCT_IDS],
                    (int) $data[static::DATA_KEY_STORE_ID],
                    $data[static::DATA_KEY_IS_SELECTED] ?? null,
                    $data[static::DATA_KEY_SELECTED_CATEGORY_ID] ?? null
                );
            }
        } catch (\Zend_Db_Adapter_Exception $e) {
            $this->logger->critical($e->getMessage());

            $status = OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED;
            $errorCode = $e->getCode();
            $message = __('Sorry, something went wrong during product attributes update. Please see log for details.');

            if (
                ($e instanceof DbConnectionException)
                || ($e instanceof DbLockWaitException)
                || ($e instanceof DbDeadlockException)
            ) {
                $status = OperationInterface::STATUS_TYPE_RETRIABLY_FAILED;
                $message = $e->getMessage();
            }
        } catch (NoSuchEntityException $e) {
            $this->logger->critical($e->getMessage());

            $status = ($e instanceof TemporaryStateExceptionInterface)
                ? OperationInterface::STATUS_TYPE_RETRIABLY_FAILED
                : OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED;

            $errorCode = $e->getCode();
            $message = $e->getMessage();
        } catch (LocalizedException $e) {
            $this->logger->critical($e->getMessage());
            $status = OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED;
            $errorCode = $e->getCode();
            $message = $e->getMessage();
        } catch (\Exception $e) {
            $this->logger->critical($e->getMessage());
            $status = OperationInterface::STATUS_TYPE_NOT_RETRIABLY_FAILED;
            $errorCode = $e->getCode();
            $message = __('Sorry, something went wrong during product attributes update. Please see log for details.');
        }

        $operation
            ->setStatus($status)
            ->setErrorCode($errorCode)
            ->setResultMessage($message);

        $this->entityManager->save($operation);
    }
}
