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

namespace CasioEMEA\Withdrawal\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\StatusFactory;
use Magento\Sales\Model\ResourceModel\Order\StatusFactory as StatusResourceFactory;

/**
 * Adds the "Withdrawal Request Submitted" custom order status and assigns it
 * to the "processing" order state for the piano withdrawal flow.
 */
class AddWithdrawalRequestSubmittedStatus implements DataPatchInterface, PatchRevertableInterface
{
    public const STATUS_WITHDRAWAL_REQUEST_SUBMITTED = 'withdrawal_request_submitted';
    public const STATUS_LABEL = 'Withdrawal Request Submitted';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly StatusFactory $statusFactory,
        private readonly StatusResourceFactory $statusResourceFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function apply(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $statusResource = $this->statusResourceFactory->create();
        $status = $this->statusFactory->create();

        $existing = $status->load(self::STATUS_WITHDRAWAL_REQUEST_SUBMITTED);
        if (!$existing->getStatus()) {
            $status->setData([
                'status' => self::STATUS_WITHDRAWAL_REQUEST_SUBMITTED,
                'label' => self::STATUS_LABEL,
            ]);
            $statusResource->save($status);
            $status->assignState(Order::STATE_PROCESSING, false, true);
        }

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * @inheritDoc
     */
    public function revert(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        $connection = $this->moduleDataSetup->getConnection();
        $connection->delete(
            $this->moduleDataSetup->getTable('sales_order_status_state'),
            ['status = ?' => self::STATUS_WITHDRAWAL_REQUEST_SUBMITTED]
        );
        $connection->delete(
            $this->moduleDataSetup->getTable('sales_order_status'),
            ['status = ?' => self::STATUS_WITHDRAWAL_REQUEST_SUBMITTED]
        );

        $this->moduleDataSetup->getConnection()->endSetup();
    }
}
