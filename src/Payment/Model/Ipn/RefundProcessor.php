<?php
/**
 * Copyright 2016 Amazon.com, Inc. or its affiliates. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License").
 * You may not use this file except in compliance with the License.
 * A copy of the License is located at
 *
 *  http://aws.amazon.com/apache2.0
 *
 * or in the "license" file accompanying this file. This file is distributed
 * on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either
 * express or implied. See the License for the specific language governing
 * permissions and limitations under the License.
 */
namespace Amazon\Payment\Model\Ipn;

use Amazon\Payment\Api\Data\PendingRefundInterface;
use Amazon\Payment\Api\Ipn\ProcessorInterface;
use Amazon\Payment\Domain\Details\AmazonRefundDetailsFactory;
use Amazon\Payment\Model\QueuedRefundUpdater;
use Amazon\Payment\Model\ResourceModel\PendingRefund\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

class RefundProcessor implements ProcessorInterface
{
    /**
     * @var AmazonRefundDetailsFactory
     */
    protected $amazonRefundDetailsFactory;

    /**
     * @var QueuedRefundUpdater
     */
    protected $queuedRefundUpdater;

    /**
     * @var CollectionFactory
     */
    protected $collectionFactory;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    public function __construct(
        AmazonRefundDetailsFactory $amazonRefundDetailsFactory,
        QueuedRefundUpdater $queuedRefundUpdater,
        CollectionFactory $collectionFactory,
        StoreManagerInterface $storeManager
    ) {
        $this->amazonRefundDetailsFactory = $amazonRefundDetailsFactory;
        $this->queuedRefundUpdater        = $queuedRefundUpdater;
        $this->collectionFactory          = $collectionFactory;
        $this->storeManager               = $storeManager;
    }

    /**
     * {@inheritDoc}
     */
    public function supports(array $ipnData)
    {
        return (isset($ipnData['NotificationType']) && 'PaymentRefund' === $ipnData['NotificationType']);
    }

    /**
     * {@inheritDoc}
     */
    public function process(array $ipnData)
    {
        $details = $this->amazonRefundDetailsFactory->create([
            'details' => $ipnData['RefundDetails']
        ]);

        $collection = $this->collectionFactory
            ->create()
            ->addFieldToFilter(PendingRefundInterface::REFUND_ID, ['eq' => $details->getRefundId()])
            ->setPageSize(1)
            ->setCurPage(1);

        $collection->getSelect()
            ->join(['so' => $collection->getTable('sales_order')], 'main_table.order_id = so.entity_id', [])
            ->where('so.store_id = ?', $this->storeManager->getStore()->getId());

        if (count($items = $collection->getItems())) {
            $pendingRefund = current($items);
            $this->queuedRefundUpdater->setThrowExceptions(true);
            $this->queuedRefundUpdater->checkAndUpdateRefund($pendingRefund->getId(), $details);
        }
    }
}
