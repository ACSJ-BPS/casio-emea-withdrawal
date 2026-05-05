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

class SetWithdrawalFlagForOrdersSentToE1NotShippedService
{
    /**
     * Constructor for SetWithdrawalFlagForOrdersSentToE1NotShippedService
     *
     * @param WithdrawalHelper $withdrawalHelper
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderItemRepositoryInterface $orderItemRepository
     */
    public function __construct(
        private readonly WithdrawalHelper $withdrawalHelper,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderItemRepositoryInterface $orderItemRepository
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
        $itemsToSave = [];
        if ($fullOrderWithdrawal) {
            foreach ($order->getAllItems() as $orderItem) {
                if ($orderItem->isDummy()) {
                    continue;
                }

                if ((float)$orderItem->getQtyShipped() > 0) {
                    $excludedItems[] = ['order_item_id' => (int)$orderItem->getItemId()];
                    continue;
                }

                $qtyToRefund = (float)$orderItem->getQtyToRefund();
                if ($qtyToRefund > 0) {
                    $qtys[$orderItem->getId()] = $qtyToRefund;
                }
                $orderItem->setData(WithdrawalHelper::WITHDRAWAL_ITEM_KEY, WithdrawalHelper::ITEM_WITHDRAWN_BEFORE_SHIPMENT);
                $orderItem->setData(WithdrawalHelper::WITHDRAWAL_QTY_KEY, (int)$orderItem->getQtyOrdered());
                $orderItem->setData(WithdrawalHelper::WITHDRAWAL_ITEM_REASON_KEY, (int)$fullWithdrawalReason);
                $itemsToSave[] = $orderItem;
            }
            $orderStatusTobeSet = $order->getStatus();
            $fullWithdrawalReasonText = $this->withdrawalHelper->getRmaReasonTextByValue($fullWithdrawalReason);
            $orderComment = 'This Order was fully withdrawn by the customer.'.$fullWithdrawalReasonText.'. The order was sent to E1 but not shipped, so the withdrawal was processed without creating an RMA. The RMA will be created after Order is shipped.';
            $withdrawnStatus = WithdrawalHelper::ORDER_FULLY_WITHDRAWN;
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
                    $excludedItems[] = $withdrawalItem;
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

                $orderItem->setData(WithdrawalHelper::WITHDRAWAL_ITEM_KEY, $itemWithdrawanStatus);
                $orderItem->setData(WithdrawalHelper::WITHDRAWAL_QTY_KEY, (int)$totalQtyWithdrawnForItem);
                $orderItem->setData(WithdrawalHelper::WITHDRAWAL_ITEM_REASON_KEY, (int)$withdrawalItem['reason']);

                $itemsToSave[] = $orderItem;
            }
            $orderStatusTobeSet = $order->getStatus();
            $orderComment = 'This Order was partially withdrawn by the customer. The order was sent to E1 but not shipped, so the withdrawal was processed without creating an RMA. The RMA will be created after Order is shiiped.';
            $withdrawnStatus = $isOrderFullyWithdrawn ? WithdrawalHelper::ORDER_FULLY_WITHDRAWN : WithdrawalHelper::ORDER_PARTIALLY_WITHDRAWN;
        }

        $order->setStatus($orderStatusTobeSet);
        $order->setData(WithdrawalHelper::WITHDRAWAL_ORDER_KEY, $withdrawnStatus);
        $order->addCommentToStatusHistory(
            $orderComment,
            $order->getStatus()                               
        );

        $order->setItems($itemsToSave);
        $this->orderRepository->save($order);

        return $excludedItems;
    }
}