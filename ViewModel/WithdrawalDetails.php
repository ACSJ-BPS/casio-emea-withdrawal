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
}