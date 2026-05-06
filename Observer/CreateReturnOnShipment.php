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

namespace CasioEMEA\Withdrawal\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use CasioEMEA\Withdrawal\Helper\Data as WithdrawalHelper;
use Magento\Sales\Api\ShipmentRepositoryInterface;

class CreateReturnOnShipment implements ObserverInterface
{
    /**
     * Constructor for CreateReturnOnShipment observer
     *
     * @param RmaRepositoryInterface $rmaRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param WithdrawalHelper $withdrawalHelper
     */    
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly WithdrawalHelper $withdrawalHelper,
        private readonly ShipmentRepositoryInterface $shipmentRepository
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer) :void
    {
        if (!$this->withdrawalHelper->isEnabled()) {
             return;
        }

        $shipment = $observer->getEvent()->getShipment();

        $order = $this->orderRepository->get($shipment->getOrderId());

        if ($this->createReturnFlag($order)) {
            $shipment->setData(WithdrawalHelper::WITHDRAWAL_FLAG, WithdrawalHelper::CREATE_RETURN);
            $shipment->setQtyShipped($shipment->getQtyShipped());
            $this->shipmentRepository->save($shipment);
        }

        return;
    }

    /**
     * Check if shipemnt needs to be returned
     * 
     * @param @order
     * @return bool
     */
    public function createReturnFlag(Order $order) :bool
    {
        $withdrawalResonStatus = [WithdrawalHelper::ITEM_WITHDRAWN_BEFORE_SHIPMENT, WithdrawalHelper::ORDER_PARTIALLY_WITHDRAWN_BEFORE_SHIPMENT];
        foreach ($order->getItems() as $item) {
            if (in_array((int)$item->getData(WithdrawalHelper::WITHDRAWAL_ITEM_KEY), $withdrawalResonStatus)) {
                return true;
            }
        }
        return false;
    }
}
