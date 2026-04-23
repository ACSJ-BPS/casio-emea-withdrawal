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

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Service\CreditmemoService;

class CreateWithdrawalCreditmemoService
{
    /**
     * Constructor for the Submit controller
     * @param Context $context
     * @param ResultFactory $resultFactory
     * @param CustomerSession $customerSession
     * @param ScopeConfigInterface $scopeConfig
     * @param OrderRepositoryInterface $orderRepository
     * @param WithdrawalHelper $withdrawalHelper
     */
    public function __construct(
        private readonly CreditmemoFactory $creditmemoFactory,
        private readonly CreditmemoService $creditmemoService
    ) {
    }

    /**
     * Creates a credit memo for the given order and invoice based on the withdrawal items
     * @param Order $order
     * @param bool $fullOrderWithdrawal
     * @param array $withdrawalItems
     * @return array
     * @throws LocalizedException
     */
    public function execute(Order $order, bool $fullOrderWithdrawal = false, array $withdrawalItems = []): array
    {
        if (!$order->canCreditmemo()) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('A credit memo cannot be created for this order #%1.', $order->getIncrementId())
            );
        }

        $invoice = $this->getRefundableInvoice($order);
        if (!$invoice) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('No refundable invoice found. Online refund requires a refundable invoice.')
            );
        }

        $qtys = [];
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
            }
        } else {
            foreach ($withdrawalItems as $withdrawalItem) {
                $itemId = $withdrawalItem['order_item_id'];
                $qty = $withdrawalItem['qty_requested'];
                $orderItem = $order->getItemById((int)$itemId);
                if (!$orderItem || $orderItem->isDummy()) {
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

                $qtyToRefund = min((float)$qty, (float)$orderItem->getQtyToRefund());
                if ($qtyToRefund > 0) {
                    $qtys[$orderItem->getId()] = $qtyToRefund;
                }
            }
        }

        if (empty($qtys)) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('No refundable unshipped items found for this order.')
            );
        }

        $creditmemo = $this->creditmemoFactory->createByInvoice($invoice, ['qtys' => $qtys]);

        if (!$creditmemo || !$creditmemo->getTotalQty()) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Unable to create credit memo.')
            );
        }

        $creditmemo->setInvoice($invoice);
        $creditmemo->setAutomaticallyCreated(true);
        $creditmemo->addComment(__('Credit memo created as part of the customer withdrawal process.'), false, false);

        // false = online refund
        $this->creditmemoService->refund($creditmemo, false);

        return [
            'creditmemo' => $creditmemo,
            'excluded_items' => $excludedItems
        ];
    }

    private function getRefundableInvoice(\Magento\Sales\Model\Order $order): ?\Magento\Sales\Model\Order\Invoice
    {
        foreach ($order->getInvoiceCollection() as $invoice) {
            if ($invoice->canRefund()) {
                return $invoice;
            }
        }

        return null;
    }
}
    