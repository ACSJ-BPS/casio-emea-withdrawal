<?php
/*******************************************************************************
 * ADOBE CONFIDENTIAL
 * ___________________
 *
 * Copyright 2025 Adobe
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

namespace CasioEMEA\Withdrawal\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order\Status;
use Magento\Sales\Model\Order\StatusFactory;
use Magento\Sales\Model\ResourceModel\Order\Status as StatusResource;

class OrderWithdrawStatus implements DataPatchInterface
{
    /**
     * @var StatusFactory
     */
    private $statusFactory;

    /**
     * @var StatusResource
     */
    private $statusResource;

    /**
     * AddPaymentReviewOrderStatus constructor.
     * @param StatusFactory $statusFactory
     * @param StatusResource $statusResource
     */
    public function __construct(
        StatusFactory $statusFactory,
        StatusResource $statusResource
    ) {
        $this->statusFactory = $statusFactory;
        $this->statusResource = $statusResource;
    }

    /**
     * Apply the data patch
     *
     * @return void
     */
    public function apply()
    {
        // Create new order status "Payment review"
        $status = $this->statusFactory->create();
        $status->setData([
            'status' => 'order_withdrawn',
            'label' => 'Order Withdrawn'
        ]);
        $this->statusResource->save($status);

        // Assign status to the "processing" state
        $status->assignState('closed', false, true);
    }

    /**
     * {@inheritdoc}
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases()
    {
        return [];
    }
}