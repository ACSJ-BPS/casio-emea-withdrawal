<?php
/*
 *  ADOBE CONFIDENTIAL
 *   ___________________
 *
 *   Copyright 2023 Adobe
 *   All Rights Reserved.
 *
 *   NOTICE: All information contained herein is, and remains
 *   the property of Adobe and its suppliers, if any. The intellectual
 *   and technical concepts contained herein are proprietary to Adobe
 *   and its suppliers and are protected by all applicable intellectual
 *   property laws, including trade secret and copyright laws.
 *   Adobe permits you to use and modify this file
 *   in accordance with the terms of the Adobe license agreement
 *   accompanying it (see LICENSE_ADOBE_PS.txt).
 *   If you have received this file from a source other than Adobe,
 *   then your use, modification, or distribution of it
 *   requires the prior written permission from Adobe.
 */

declare(strict_types=1);

namespace CasioEMEA\Withdrawal\Cron;

use CasioEMEA\Withdrawal\Helper\Data as WithdrawalHelper;
use CasioEMEA\Withdrawal\Service\CreateReturnOnWithdrawalShipmentService;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;
use Psr\Log\LoggerInterface;

class CreateReturnForWithdrawnOrderAfterShipment
{

    /**
     * Constructor for CreateReturnOnShipment observer
     *
     * @param OrderRepositoryInterface $orderRepository
     * @param WithdrawalHelper $withdrawalHelper
     * @param ShipmentRepositoryInterface $shipmentRepository
     * @param CreateReturnOnWithdrawalShipmentService $createReturnOnWithdrawalShipmentService
     * @param ShipmentCollectionFactory $shipmentCollectionFactory
     * @param LoggerInterface $logger
     */   
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly WithdrawalHelper $withdrawalHelper,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly CreateReturnOnWithdrawalShipmentService $createReturnOnWithdrawalShipmentService,
        private readonly ShipmentCollectionFactory $shipmentCollectionFactory,
        private readonly LoggerInterface $logger
    ) {
    }
    
    /**
     * @return void
     */
    public function execute() :void
    {
        $shipmentCollection = $this->shipmentCollectionFactory->create();

        $shipmentCollection->addFieldToFilter(WithdrawalHelper::WITHDRAWAL_FLAG, WithdrawalHelper::CREATE_RETURN);

        foreach ($shipmentCollection as $shipment) {
            try {
                $this->createReturnOnWithdrawalShipmentService->execute((int)$shipment->getEntityId());
                $this->logger->info('Successfully created return for shipment ' . $shipment->getIncrementId());
            } catch (\Exception $e) {
                $this->logger->error(
                    'Failed to create return for shipment ' . $shipment->getIncrementId() . ': ' . $e->getMessage(),
                    ['exception' => $e]
                );
                // Continue processing other shipments even if this one fails
                continue;
            }
        }
    }

}