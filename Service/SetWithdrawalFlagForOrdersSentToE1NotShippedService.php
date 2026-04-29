<?php
/*******************************************************************************
 * ADOBE CONFIDENTIAL
 * ___________________
 *
 * Copyright 2026 Adobe
 * All Rights Reserved.
 *
 * NOTICE: All information contained herein is, and remains
 * the property of Adobe and its suppliers, if any. The intellectual
 * and technical concepts contained herein are proprietary to Adobe
 * and its suppliers and are protected by all applicable intellectual
 * property laws, including trade secret and copyright laws.
 * Adobe permits you to use and modify this file
 * in accordance with the terms of the Adobe license agreement
 * accompanying it (see LICENSE_ADOBE_PS.txt).
 * If you have received this file from a source other than Adobe,
 * then your use, modification, or distribution of it
 * requires the prior written permission from Adobe.
 ******************************************************************************/

declare(strict_types=1);

namespace CasioEMEA\Withdrawal\Service;

use Magento\Sales\Model\Order;
use CasioEMEA\Withdrawal\Helper\Data as WithdrawalHelper;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\Item;
use Magento\Sales\Model\ResourceModel\Order\ItemFactory as OrderItemResourceFactory; 

class SetWithdrawalFlagForOrdersSentToE1NotShippedService
{
    /**
     * Constructor for SetWithdrawalFlagForOrdersSentToE1NotShippedService
     *
     * @param WithdrawalHelper $withdrawalHelper
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderItemRepositoryInterface $orderItemRepository
     * @param OrderItemResourceFactory $orderItemResourceFactory
     */
    public function __construct(
        private readonly WithdrawalHelper $withdrawalHelper,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderItemRepositoryInterface $orderItemRepository,
        private readonly OrderItemResourceFactory $orderItemResourceFactory
    ) {
    }

    /**
     * Executes the service to set withdrawal flags for orders that are sent to E1 but not shipped
     *
     * @param Order $order
     * @param bool $fullOrderWithdrawal
     * @param array $withdrawalItems
     * @param string $fullWithdrawalReason
     * @return array
     * @throws LocalizedException
     */
    public function execute(Order $order, bool $fullOrderWithdrawal = false, array $withdrawalItems = [], string $fullWithdrawalReason = "0"): array
    {
        $excludedItems = [];
        if ($fullOrderWithdrawal) {
            foreach ($order->getAllItems() as $orderItem) {
                if ($orderItem->isDummy()) {
                    continue;
                }

                if ((float)$orderItem->getQtyShipped() > 0) {
                    $excludedItems[] = [
                        'item_id'      => $orderItem->getId(),
                        'sku'          => $orderItem->getSku(),
                        'name'         => $orderItem->getName(),
                        'qty_ordered'  => (float)$orderItem->getQtyOrdered(),
                        'qty_shipped'  => (float)$orderItem->getQtyShipped(),
                        'qty_refunded' => (float)$orderItem->getQtyRefunded(),
                    ];
                    continue;
                }

                $qtyToRefund = (float)$orderItem->getQtyToRefund();
                if ($qtyToRefund > 0) {
                    $qtys[$orderItem->getId()] = $qtyToRefund;
                }
                $this->updateOrderItem((int)$orderItem->getId(), WithdrawalHelper::ITEM_WITHDRAWN_BEFORE_SHIPMENT, (int)$orderItem->getQtyOrdered(), (int)$fullWithdrawalReason);
            }
            $orderStatusTobeSet = $order->getStatus();
            $fullWithdrawalReasonText = $this->withdrawalHelper->getRmaReasonTextByValue($fullWithdrawalReason);
            $orderComment = 'This Order was fully withdrawn by the customer.'.$fullWithdrawalReasonText.'. The order was sent to E1 but not shipped, so the withdrawal was processed without creating an RMA. The RMA will be created after Order is shipped.';
            $withdrawnStatus = WithdrawalHelper::ORDER_WITHDRAWN_BEFORE_SHIPMENT;
        } else {
            $totalQtyOrdered = 0;
            $isOrderFullyWithdrawn = true;
            foreach ($withdrawalItems as $withdrawalItem) {
                $orderItem = $this->orderItemRepository->get($withdrawalItem['order_item_id']);
                $qty = $withdrawalItem['qty_requested'];
                if (!$orderItem || $orderItem->isDummy()) {
                    continue;
                }

                $totalQtyOrdered += (int)$orderItem->getQtyOrdered();

                if ((float)$orderItem->getQtyShipped() > 0) {
                    $excludedItems[] = [
                        'item_id'      => $orderItem->getId(),
                        'sku'          => $orderItem->getSku(),
                        'name'         => $orderItem->getName(),
                        'qty_ordered'  => (float)$orderItem->getQtyOrdered(),
                        'qty_shipped'  => (float)$orderItem->getQtyShipped(),
                        'qty_refunded' => (float)$orderItem->getQtyRefunded(),
                    ];
                    continue;
                }

                $qtyToRefund = min((float)$qty, (float)$orderItem->getQtyToRefund());
                if ($qtyToRefund > 0) {
                    $qtys[$orderItem->getId()] = $qtyToRefund;
                }
                $totalQtyWithdrawnForItem = (int)$orderItem->getData(WithdrawalHelper::WITHDRAWAL_QTY_KEY) + (int)$qtyToRefund;
                
                if ((int)$orderItem->getQtyOrdered() === $totalQtyWithdrawnForItem) {
                    $itemWithdrawanStatus = WithdrawalHelper::ITEM_WITHDRAWN_BEFORE_SHIPMENT;
                } else {
                    $itemWithdrawanStatus = WithdrawalHelper::ITEM_PARTIALLY_WITHDRAWN_BEFORE_SHIPMENT;
                    $isOrderFullyWithdrawn = false;
                }

                $this->updateOrderItem((int)$orderItem->getId(), $itemWithdrawanStatus, $totalQtyWithdrawnForItem, (int)$withdrawalItem['reason']);
            }
            $orderStatusTobeSet = $order->getStatus();
            $orderComment = 'This Order was partially withdrawn by the customer. The order was sent to E1 but not shipped, so the withdrawal was processed without creating an RMA. The RMA will be created after Order is shiiped.';
            $withdrawnStatus = $isOrderFullyWithdrawn ? WithdrawalHelper::ORDER_WITHDRAWN_BEFORE_SHIPMENT : WithdrawalHelper::ORDER_PARTIALLY_WITHDRAWN_BEFORE_SHIPMENT;
        }

        $order->setStatus($orderStatusTobeSet);
        $order->setData('withdrawal_order_status', $withdrawnStatus);
        $order->addCommentToStatusHistory(
            $orderComment,
            $order->getStatus()                               
        );

        $this->orderRepository->save($order);

        return [
            'excluded_items' => $excludedItems
        ];
    }

    /**
     * Update the order item with the withdrawal status and quantity
     * @param int $itemId
     * @param int $withdrawnStatus
     * @param int $withdrawnQty
     * @return void
     */
    private function updateOrderItem(int $itemId, int $withdrawnStatus, int $withdrawnQty, int $reason): void
    {
        $connection = $this->orderItemResourceFactory->create()->getConnection();
        $tableName = $connection->getTableName('sales_order_item');

        $dataToUpdate = [
            'withdrawal_item_status' => $withdrawnStatus,
            'withdrawal_qty' => $withdrawnQty,
            'withdrawal_item_reason' => $reason
        ];

        $whereCondition = [
            'item_id = ?' => $itemId
        ];

        $connection->update($tableName, $dataToUpdate, $whereCondition);
    }
}