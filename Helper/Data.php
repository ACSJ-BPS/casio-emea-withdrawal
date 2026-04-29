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
use CasioEMEA\E1Integration\Model\Config\Source\RmaReasonList;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    public const XML_PATH_WITHDRAWAL_ENABLED = 'casio_withdrawal/configuration/enable';

    public const WITHDRAWAL_STATUS = 'order_withdrawn';

    public const WITHDRAWAL_ORDER_KEY = 'withdrawal_order_status';

    public const WITHDRAWAL_ITEM_KEY = 'withdrawal_item_status';

    public const WITHDRAWAL_ITEM_REASON_KEY = 'withdrawal_item_reason';

    public const WITHDRAWAL_QTY_KEY = 'withdrawal_qty';

    public const ORDER_NOT_WITHDRAWN = 0;

    public const ORDER_FULLY_WITHDRAWN = 1;

    public const ORDER_PARTIALLY_WITHDRAWN = 2;

    public const ORDER_WITHDRAWN_BEFORE_SHIPMENT = 3;
    
    public const ORDER_PARTIALLY_WITHDRAWN_BEFORE_SHIPMENT = 4;

    public const ORDER_WITHDRAWN_AFTER_SHIPMENT_NOT_DELIVERED = 5;

    public const ORDER_PARTIALLY_WITHDRAWN_AFTER_SHIPMENT_NOT_DELIVERED = 6;

    public const ORDER_WITHDRAWN_AFTER_SHIPMENT_DELIVERED = 7;

    public const ORDER_PARTIALLY_WITHDRAWN_AFTER_SHIPMENT_DELIVERED = 8;

    public const ITEM_NOT_WITHDRAWN = 0;

    public const ITEM_FULLY_WITHDRAWN = 1;

    public const ITEM_PARTIALLY_WITHDRAWN = 2;

    public const ITEM_WITHDRAWN_BEFORE_SHIPMENT = 3;
    
    public const ITEM_PARTIALLY_WITHDRAWN_BEFORE_SHIPMENT = 4;

    public const ITEM_WITHDRAWN_AFTER_SHIPMENT_NOT_DELIVERED = 5;

    public const ITEM_PARTIALLY_WITHDRAWN_AFTER_SHIPMENT_NOT_DELIVERED = 6;

    public const ITEM_WITHDRAWN_AFTER_SHIPMENT_DELIVERED = 7;

    public const ITEM_PARTIALLY_WITHDRAWN_AFTER_SHIPMENT_DELIVERED = 8;

     /**
     * Constructor for the Data helper
     *
     * @param Context $context
     * @param CustomerSession $customerSession
     * @param RmaReasonList $rmaReasonList
     */

    public function __construct(
        Context $context,
        private readonly CustomerSession $customerSession,
        private readonly RmaReasonList $rmaReasonList
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
        if (((int)$item->getData(self::WITHDRAWAL_ITEM_KEY) !== self::ITEM_WITHDRAWN_BEFORE_SHIPMENT)
            && ((int)$item->getData(self::WITHDRAWAL_ITEM_KEY) !== self::ITEM_FULLY_WITHDRAWN)) {
            $availableQty = $item->getQtyOrdered() - $item->getData(self::WITHDRAWAL_QTY_KEY);
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
     * Check if no item in the order has been refunded
     * An order is considered to have no refunded items if all items have their refunded quantity equal to zero
     *
     * @param Order $order
     * @return bool
     */
    public function ifNoItemRefunded(Order $order): bool
    {
        foreach ($order->getItems() as $item) {
            if ((int)$item->getData(self::WITHDRAWAL_ITEM_KEY) !== 0) {
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

    /**
     * Get withdrawal items for the order
     * Only returns items that are fully invoiced but not fully shipped
     *
     * @param Order $order
     * @return string
     */
    public function getWithdrawalSubmissionUrl(Order $order): string
    {
        if ($this->customerSession->isLoggedIn()) {
            return $this->_getUrl('withdrawal/customer/withdraw/', ['order_id' => $order->getId()]);
        } else {
            return $this->_getUrl('withdrawal/guest/withdraw/', ['order_id' => $order->getId()]);
        }
    }
    
    /**
     * Check if the order has not been sent to E1
     * An order is considered not sent to E1 if its status is processing and the sync flag is 0
     * @param Order $order
     * @return bool
     */
    public function orderNotSentToE1(Order $order): bool
    {
        if ($order->getStatus() === Order::STATE_PROCESSING && (int)$order->getData('order_sync_to_e1') === 0) {
            return true;
        }
        return false;
    }

    /**
     * Check if the order has been sent to E1 but not shipped
     * An order is considered sent to E1 but not shipped if its sync flag is 1 and all items have their shipped quantity equal to zero
     * @param Order $order
     * @return bool
     */
    public function orderSentToE1NotShipped(Order $order): bool
    {
        if ((int)$order->getData('order_sync_to_e1') === 1) {
            foreach ($order->getItems() as $item) {
                if ((int)$item->getQtyShipped() > 0) {
                    return false;
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Check if the order can be withdrawn
     * An order can be withdrawn if it can have a credit memo created for it
     * @param Order $order
     * @return bool
     */
    public function canWithdrawOrder(Order $order): bool
    {
        return !((int)$order->getData(self::WITHDRAWAL_ORDER_KEY) === self::ORDER_FULLY_WITHDRAWN) 
        && !((int)$order->getData(self::WITHDRAWAL_ORDER_KEY) === self::ORDER_WITHDRAWN_AFTER_SHIPMENT_DELIVERED)
        && !((int)$order->getData(self::WITHDRAWAL_ORDER_KEY) === self::ORDER_WITHDRAWN_AFTER_SHIPMENT_NOT_DELIVERED)
        && !((int)$order->getData(self::WITHDRAWAL_ORDER_KEY) === self::ORDER_WITHDRAWN_BEFORE_SHIPMENT);
    }

    /**
     * Get RMA reason options for the withdrawal form
     * The options are retrieved from the RmaReasonList class which gets them from the E
     * AV attribute options for the 'reason' attribute of RMA entities
     * @return array
     */
    public function getRmaReasonOptions(): array
    {
        return $this->rmaReasonList->toOptionArray();
    }

    /**
     * Get RMA reason text by value
     * The text is retrieved from the RmaReasonList class by matching
     * the given value with the options' values and returning the corresponding label
     * @param string $value
     * @return string
     */
    public function getRmaReasonTextByValue(string $value): string
    {
        $options = $this->rmaReasonList->toOptionArray();
        foreach ($options as $option) {
            if ($option['value'] === (int)$value) {
                return $option['label'];
            }
        }
        return '';
    }
}