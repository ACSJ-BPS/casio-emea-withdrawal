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

namespace CasioEMEA\Withdrawal\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Customer\Model\Session as CustomerSession;
use  Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    const XML_PATH_WITHDRAWAL_ENABLED = 'casio_withdrawal/configuration/enable';

    public function __construct(
        Context $context,
        private readonly CustomerSession $customerSession
    ) {
        parent::__construct($context);
    }

    /**
     * Check if the withdrawal module is enabled in the configuration
     * @return bool
     */
    public function isEnabled() : bool
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_PATH_WITHDRAWAL_ENABLED)) {
            return false;
        }
        return true;
    }

    /**
     * Get the URL for creating a withdrawal request based on the order and customer login status
     *
     * @param Order $order
     * @return string
     */
    public function getWithdrawCreateUrl(Order $order) : string
    {
        if ($this->customerSession->isLoggedIn()) {
            return $this->_getUrl('withdrawal/customer/create', ['order_id' => $order->getId()]);
        } else {
            return $this->_getUrl('withdrawal/guest/create', ['order_id' => $order->getId()]);
        }
    }

    /**
     * Get the URL for submitting a withdrawal request based on the order
     *
     * @param Order $order
     * @return string
     */
    public function getWithdrawalSubmitUrl(Order $order) : string
    {
        return $this->_getUrl('withdrawal/customer/submit', ['order_id' => $order->getId()]);
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
        $availableQty = 0;
        if ((int)$item->getQtyInvoiced() === (int)$item->getQtyOrdered() && (int)$item->getQtyShipped() === 0) {
            $availableQty = (int)$item->getQtyInvoiced() - (int)$item->getQtyRefunded();
        }
        return $availableQty;
    }

    /**
     * Check if the order is fully shipped
     * An order is considered fully shipped if all items have their ordered quantity equal to the shipped quantity
     * @param Order $order
     * @return bool
     */
    public function isOrderFullyShipped(Order $order): bool
    {
        foreach ($order->getItems() as $item) {
            if ($item->getQtyOrdered() > $item->getQtyShipped()) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if the order is not shipped
     * An order is considered not shipped if all items have their shipped quantity equal to zero
     * @param Order $order
     * @return bool
     */
    public function isItemNotShipped(Item $item): bool
    {
        if ($item->getQtyShipped() > 0) {
            return false;
        }
        return true;
    }
}