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

use Magento\Framework\App\Area;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Rma\Model\RmaFactory;
use Magento\Rma\Model\Rma\Source\Status;
use Magento\Rma\Api\RmaRepositoryInterface;
use Magento\Rma\Model\ItemFactory as RmaItemFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Store\Model\App\Emulation;
use Psr\Log\LoggerInterface;
use CasioEMEA\Withdrawal\Helper\Data as WithdrawalHelper;
use Magento\Rma\Model\ResourceModel\Rma as RmaResourceModel;
use Magento\Rma\Model\ResourceModel\Item as RmaItemResourceModel;
use Magento\Sales\Model\Order\Item;
use Magento\Rma\Model\Rma\Status\HistoryFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Magento\Rma\Api\Data\RmaInterface;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use CasioEMEA\Withdrawal\Model\Email\WithdrawalConfirmationEmailSender;

class CreateReturnOnWithdrawalShipmentService
{
    /**
     * constructor
     *
     * @param RmaFactory $rmaFactory
     * @param RmaRepositoryInterface $rmaRepository
     * @param ShipmentRepositoryInterface $shipmentRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param RmaItemFactory $rmaItemFactory
     * @param Emulation $appEmulation
     * @param LoggerInterface $logger
     * @param WithdrawalHelper $withdrawalHelper
     * @param RmaResourceModel $rmaResourceModel
     * @param RmaItemResourceModel $rmaItemResourceModel
     * @param HistoryFactory $statusHistoryFactory
     * @param OrderItemRepositoryInterface $orderItemRepository
     * @param WithdrawalConfirmationEmailSender $withdrawalConfirmationEmailSender
     */
    public function __construct(
        private readonly RmaFactory $rmaFactory,
        private readonly RmaRepositoryInterface $rmaRepository,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RmaItemFactory $rmaItemFactory,
        private readonly Emulation $appEmulation,
        private readonly LoggerInterface $logger,
        private readonly WithdrawalHelper $withdrawalHelper,
        private readonly RmaResourceModel $rmaResourceModel,
        private readonly RmaItemResourceModel $rmaItemResourceModel,
        private readonly HistoryFactory $statusHistoryFactory,
        private readonly OrderItemRepositoryInterface $orderItemRepository,
        private readonly WithdrawalConfirmationEmailSender $withdrawalConfirmationEmailSender
    ) {
    }
    
    /**
     * Create an RMA for the given shipment ID
     *
     * @param  int $shipmentId
     * @return \Magento\Rma\Model\Rma
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function execute(int $shipmentId): \Magento\Rma\Model\Rma
    {
        // Load shipment
        $shipment = $this->shipmentRepository->get($shipmentId);

        // Load order fresh from repository
        $order = $this->orderRepository->get($shipment->getOrderId());

        // Validate order is eligible for return
        $this->validateOrderEligibility($order);

        // Build RMA item objects
        $rmaItems = $this->buildRmaItems($shipment);

        if (empty($rmaItems)) {
            throw new LocalizedException(
                __('No eligible items found in shipment #%1', $shipment->getIncrementId())
            );
        }

        // Emulate frontend area — required for RMA save to work
        $this->appEmulation->startEnvironmentEmulation(
            (int) $order->getStoreId(),
            Area::AREA_FRONTEND,
            true
        );

        try {
            // Built the RMA
            $post = [
                'items' => $rmaItems,
                'customer_custom_email' => ''
            ];
            $rmaObject = $this->buildRma($order, $shipment, $rmaItems, $post);
            if (!$rmaObject->saveRma($post)) {
                    throw new \Magento\Framework\Exception\LocalizedException(
                        __('Failed to save RMA entity for Order #%1', $order->getIncrementId())
                    );
                }
            $statusHistory = $this->statusHistoryFactory->create();
            $statusHistory->setRmaEntityId($rmaObject->getEntityId());
            $statusHistory->saveSystemComment();
            $shipment->setData(WithdrawalHelper::WITHDRAWAL_FLAG, WithdrawalHelper::RETURN_CREATED);
            $this->updateOrderAndItemsForWithdrawal($order, $rmaItems);
            $this->shipmentRepository->save($shipment);
            $this->withdrawalConfirmationEmailSender->send($order, (int)$rmaObject->getEntityId());
        } finally {
            // Always stop emulation even if exception occurs
            $this->appEmulation->stopEnvironmentEmulation();
        }

        return $rmaObject;
    }

    /**
     * Update Order Item Status for withdrawal
     * @param Order $Order
     * @param array $rmaItems
     * returns void
     */
    private function updateOrderAndItemsForWithdrawal(Order $order, array $rmaItems): void
    {
        $itemsToSave = [];
        foreach ($rmaItems as $rmaItem) {
            $orderItem = $this->orderItemRepository->get((int)$rmaItem['order_item_id']);
            $orderItemWithdrawalStatus = (int)$orderItem->getQtyOrdered() === (int)$orderItem->getData(WithdrawalHelper::WITHDRAWAL_QTY_KEY) 
            ? WithdrawalHelper::ITEM_FULLY_WITHDRAWN : WithdrawalHelper::ITEM_PARTIALLY_WITHDRAWN;

            $orderItem->setData(WithdrawalHelper::WITHDRAWAL_ITEM_KEY, $orderItemWithdrawalStatus);
            $itemsToSave[] = $orderItem;
        }

        $order->setItems($itemsToSave);
        $this->orderRepository->save($order);
    }

    /**
     * Validate that the order can have an RMA created
     *
     * @param  \Magento\Sales\Model\Order $order
     * returns bool
     */
    private function validateOrderEligibility(\Magento\Sales\Model\Order $order): bool
    {
        $validStates = [
            \Magento\Sales\Model\Order::STATE_PROCESSING,
            \Magento\Sales\Model\Order::STATE_COMPLETE,
        ];

        if (!in_array($order->getState(), $validStates)) {
            return false;
        }

        return true;
    }

    /**
     * Prevent duplicate RMAs for the same order
     *
     * @param  \Magento\Sales\Model\Order $order
     * @throws LocalizedException
     */
    private function validateNoDuplicateRma(\Magento\Sales\Model\Order $order): void
    {
        $searchCriteria = \Magento\Framework\Api\SearchCriteriaBuilder::class;

        // Using object manager pattern avoided — inject SearchCriteriaBuilder if needed
        // This is a lightweight check via the RMA collection
        $existingRma = $this->rmaRepository->getList(
            (new \Magento\Framework\Api\SearchCriteriaBuilder())
                ->addFilter('order_id', $order->getId())
                ->addFilter('status', Status::STATE_PENDING)
                ->create()
        );

        if ($existingRma->getTotalCount() > 0) {
            throw new LocalizedException(
                __('A pending RMA already exists for Order #%1', $order->getIncrementId())
            );
        }
    }

    /**
     * Build RMA item data array from shipment items
     * Only includes items that are fully invoiced and not fully shipped, and have a withdrawal reason set
     * @param Shipment $shipment
     * @return array
     */
    private function buildRmaItems(Shipment $shipment): array
    {
        $rmaItems = [];
        $order    = $shipment->getOrder();

        foreach ($shipment->getAllItems() as $shipmentItem) {
            $orderItem = $shipmentItem->getOrderItem();

            // Skip child items (e.g., configurable children — the parent handles qty)
            if ($orderItem->getParentItemId()) {
                continue;
            }

            if (!$this->isItemEligibleForReturn($orderItem)) {
                continue;
            }

            $qty = (int) $orderItem->getData(WithdrawalHelper::WITHDRAWAL_QTY_KEY);
            if ($qty <= 0) {
                continue;
            }

            // Get reason code from order item, default to 1 (Not Received) if not set
            $reasonCode = (string)$orderItem->getData(WithdrawalHelper::WITHDRAWAL_ITEM_REASON_KEY);
            if (empty($reasonCode)) {
                $reasonCode = '1'; // Default reason code
            }
            
            $rmaItems[] = [
                'order_item_id' => $orderItem->getId(),
                'qty_requested' => (string)$qty,
                'condition'     => "0", // Valid condition code (0 = Used)
                'reason'        => $reasonCode,
                'reason_other'  => '' // Add reason_other field for custom reason
            ];
        }

        return $rmaItems;
    }

    /**
     * Build RMA Item objects from shipment items
     *
     * @param  \Magento\Sales\Model\Order\Shipment $shipment
     * @return \Magento\Rma\Model\Item[]
     */
    private function buildRmaItemObjects(\Magento\Sales\Model\Order\Shipment $shipment): array
    {
        $rmaItems = [];

        foreach ($shipment->getAllItems() as $shipmentItem) {
            $orderItem = $shipmentItem->getOrderItem();

            // Skip child items (configurable / bundle children)
            if ($orderItem->getParentItemId()) {
                continue;
            }

            // Skip virtual and downloadable products
            if (in_array($orderItem->getProductType(), ['virtual', 'downloadable'])) {
                continue;
            }

            if (!$this->isItemEligibleForReturn($orderItem)) {
                continue;
            }

            $qty = (int) $orderItem->getData(WithdrawalHelper::WITHDRAWAL_QTY_KEY);
            if ($qty <= 0) {
                continue;
            }

            // Get reason code from order item, default to 1 (Not Received) if not set
            $reasonId = (string)$orderItem->getData(WithdrawalHelper::WITHDRAWAL_ITEM_REASON_KEY);
            if (empty($reasonId)) {
                $reasonId = '1'; // Default reason code
            }

            /** @var \Magento\Rma\Model\Item $rmaItem */
            $rmaItem = $this->rmaItemFactory->create();
            $rmaItem->setOrderItemId($orderItem->getId());
            $rmaItem->setQtyRequested($qty);
            $rmaItem->setReason($reasonId);
            $rmaItem->setCondition('0');
            $rmaItem->setReasonOther('');
            $rmaItem->setStatus(\Magento\Rma\Model\Item\Attribute\Source\Status::STATE_PENDING);

            // Key by order item ID — required by RMA validator
            $rmaItems[$orderItem->getId()] = $rmaItem;
        }

        return $rmaItems;
    }

    /**
     * Check if an item is eligible for return based on invoiced and shipped quantities
     * An item is eligible if it is fully invoiced and not fully shipped
     * @param Item $item
     * @return bool
     */
    private function isItemEligibleForReturn(Item $orderItem): bool
    {
        $withdrawalResonStatus = [WithdrawalHelper::ITEM_WITHDRAWN_BEFORE_SHIPMENT, WithdrawalHelper::ORDER_PARTIALLY_WITHDRAWN_BEFORE_SHIPMENT];
        // Check if item is fully invoiced and not fully shipped
        if (in_array((int)$orderItem->getData(WithdrawalHelper::WITHDRAWAL_ITEM_KEY), $withdrawalResonStatus)) {
            return true;
        }
        return false;
    }

    /**
     * Create and persist the RMA
     *
     * @param  \Magento\Sales\Model\Order          $order
     * @param  \Magento\Sales\Model\Order\Shipment $shipment
     * @param  \Magento\Rma\Model\Item[]           $rmaItems
     * @return \Magento\Rma\Model\Rma
     * @throws LocalizedException
     */
    private function createRma(
        \Magento\Sales\Model\Order $order,
        \Magento\Sales\Model\Order\Shipment $shipment,
        array $rmaItems
    ): \Magento\Rma\Model\Rma {
           /** @var \Magento\Rma\Model\Rma $rma */
        $rma = $this->rmaFactory->create();

        $rma->setData([
            'status'                => (string) Status::STATE_PENDING,
            'date_requested'        => (string) date('Y-m-d H:i:s'),
            'order_id'              => (int) $order->getId(),
            'order_increment_id'    => (string) $order->getIncrementId(),
            'store_id'              => (int) $order->getStoreId(),
            'customer_id'           => $order->getCustomerId() ? (int) $order->getCustomerId() : null,
            'customer_name'         => trim((string) $order->getCustomerFirstname() . ' ' . (string) $order->getCustomerLastname()),
            'customer_email'        => (string) $order->getCustomerEmail(),
            'customer_custom_email' => 0,
        ]);

        // Step 1 — Save RMA without items first to get the RMA ID
        $this->rmaResourceModel->save($rma);

        $rmaId = (int) $rma->getId();

        if (!$rmaId) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Failed to save RMA entity for Order #%1', $order->getIncrementId())
            );
        }

        // Step 2 — Save each RMA item separately with the RMA ID
        foreach ($rmaItems as $rmaItem) {
            $rmaItem->setRmaEntityId($rmaId);
            $this->rmaItemResourceModel->save($rmaItem);
        }

        $this->logger->info(sprintf(
            'AutoReturn: RMA #%s created for Order #%s (Shipment #%s)',
            $rma->getIncrementId(),
            $order->getIncrementId(),
            $shipment->getIncrementId()
        ));

        return $rma;
    }

    /**
     * Create the RMA record
     * This method can be expanded to set additional RMA data as needed, such as custom attributes, comments, etc.
     * @param Order $order
     * @param Shipment $shipment
     * @param array $rmaItems
     * @return RmaInterface
     */
    private function buildRma(
        Order $order,
        Shipment $shipment,
        array $rmaItems,
        array $post = []
    ): RmaInterface {
        /** @var \Magento\Rma\Model\Rma $rma */
        $rmaModel = $this->rmaFactory->create();

        $rmaData = [
            'status'          => Status::STATE_PENDING,
            'date_requested'  => date('Y-m-d H:i:s'),
            'order_id'        => $order->getId(),
            'order_increment_id' => $order->getIncrementId(),
            'store_id'        => $order->getStoreId(),
            'customer_id'     => $order->getCustomerId(),
            'customer_name'   => $order->getCustomerName(),
            'customer_email'  => $order->getCustomerEmail(),
            'customer_custom_email' => isset($post['customer_custom_email']) ? $post['customer_custom_email'] : ''
        ];

        $rmaModel->setData($rmaData);

        return $rmaModel;
    }

    /**
     * Get the first valid option ID from an attribute source model
     *
     * @param  mixed $sourceModel
     * @return int
     */
    private function getFirstOptionId($sourceModel): int
    {
        foreach ($sourceModel->getAllOptions() as $option) {
            if (!empty($option['value'])) {
                return (int) $option['value'];
            }
        }
        return 0;
    }
}