<?php
/*
 * ADOBE CONFIDENTIAL
 * ___________________
 *
 * Copyright 2022 Adobe
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
 */
declare(strict_types=1);

namespace CasioEMEA\Withdrawal\ViewModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use CasioEMEA\Withdrawal\Helper\Data as WithdrawalHelper;

class WithdrawalDetails implements ArgumentInterface
{
    /**
     * Constructor
     *
     * @param ScopeConfigInterface $scopeConfig
     * @param WithdrawalHelper $withdrawalHelper
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WithdrawalHelper $withdrawalHelper
    ){
    }

    /**
     * Get withdrawal items for the order
     * Only returns items that are fully invoiced but not fully shipped
     *
     * @param Order $order
     * @return array
     */
    public function getWithdrawalItems(Order $order): array
    {
        $invoicedItems = [];
        
        foreach ($order->getItems() as $item) {
            // Check if item is invoiced
            if ((int)$item->getQtyInvoiced() ===  (int)$item->getQtyOrdered() && (int)$item->getQtyShipped() === 0) {
                $invoicedItems[] = $item;
            }
        }
        
        return $invoicedItems;
    }

    /**
     * Get all items for the order
     *
     * @param Order $order
     * @return array
     */
    public function getOrderItems(Order $order) :array
    {
        $orderItems = [];
        foreach ($order->getItems() as $item) {
            $orderItems[] = [
                'name' => $item->getName(),
                'sku' => $item->getSku(),
                'ordered_qty' => (int)$item->getQtyOrdered()
            ];
        }
        return $orderItems;
    }

    /**
     * Check if the order is fully shipped
     * An order is considered fully shipped if all items have their ordered quantity equal to the shipped quantity
     * @param Order $order
     * @return bool
     */
    public function isOrderFullyShipped(Order $order): bool
    {
        return $this->withdrawalHelper->isOrderFullyShipped($order);
    }

    /**
     * Check if the order is not shipped
     * An order is considered not shipped if all items have their shipped quantity equal to zero
     * @param Order $order
     * @return bool
     */
    public function isItemNotShipped(Item $item): bool
    {
        return $this->withdrawalHelper->isItemNotShipped($item);
    }

    /**
     * Get the available quantity for withdrawal for a given order item
     * The available quantity is calculated as the invoiced quantity minus the refunded quantity
     * Only applicable for items that are fully invoiced but not shipped
     *
     * @param Item $item
     * @return int
     */
    public function getWithdrawalItemsAvailableQty(Item $item): int
    {
        return $this->withdrawalHelper->getWithdrawalItemsAvailableQty($item);
    }

    /**
     * Get the URL for withdrawal submission for the given order
     * The URL is generated based on the order ID and the route defined in the module
     * @param Order $order
     * @return string
     */
    public function getWithdrawalSubmissionUrl(Order $order): string
    {
        return $this->withdrawalHelper->getWithdrawalSubmissionUrl($order);
    }

    /**
     * Check if no item in the order has been refunded
     * An order is considered to have no refunded items if all items have their refunded quantity equal to zero
     *
     * @param Order $order
     * @return bool
     */
    public function ifNoItemRefunded(Order $order): bool
    {
        return $this->withdrawalHelper->ifNoItemRefunded($order);
    }

    /**
     * Get RMA reason options for the withdrawal form
     * The options are retrieved from the RmaReasonList class which gets them from the E
     * AV attribute options for the 'reason' attribute of RMA entities
     * @return array
     */
    public function getRmaReasonOptions(): array
    {
        return $this->withdrawalHelper->getRmaReasonOptions();  
    }
}