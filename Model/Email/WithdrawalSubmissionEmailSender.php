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

namespace CasioEMEA\Withdrawal\Model\Email;

use CasioEMEA\Withdrawal\Helper\Data as WithdrawalHelper;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;

/**
 * Sends the "Withdrawal submission emails" transactional email to the customer
 * when a piano order withdrawal is submitted.
 */
class WithdrawalSubmissionEmailSender
{
    /**
     * Constructor
     *
     * @param TransportBuilder $transportBuilder
     * @param StateInterface $inlineTranslation
     * @param ScopeConfigInterface $scopeConfig
     * @param WithdrawalHelper $withdrawalHelper
     * @param LoggerInterface $logger
     * @param ShipmentCollectionFactory $shipmentCollectionFactory
     */
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WithdrawalHelper $withdrawalHelper,
        private readonly LoggerInterface $logger,
        private ShipmentCollectionFactory $shipmentCollectionFactory
    ) {
    }

    /**
     * Send piano withdrawal submission email
     *
     * @param Order $order
     * @param integer $scenario
     * @param array $shippedItems
     * @return boolean
     */
    public function send(Order $order, int $scenario = 0, $shippedItems = []): bool
    {
        $storeId = (int)$order->getStoreId();

        if (!$this->withdrawalHelper->isOrderWithdrawalSubmissionEmailEnabled($storeId)) {
            return false;
        }

        $customerEmail = $order->getCustomerEmail();
        if (empty($customerEmail)) {
            return false;
        }

        $template = $this->withdrawalHelper->getPianoEmailConfig(
            WithdrawalHelper::XML_PATH_SUBMISSION_EMAIL_TEMPLATE,
            $storeId
        );
        if (empty($template)) {
            return false;
        }

        $senderInfo = $this->getSenderInfo($storeId);
        $copyTo = $this->getCopyToList($storeId);
        $copyMethod = $this->withdrawalHelper->getPianoEmailConfig(
            WithdrawalHelper::XML_PATH_SUBMISSION_EMAIL_COPY_METHOD,
            $storeId
        );

        $templateVars = [
            'customer_name' => $order->getCustomerName(),
            'order_increment_id' => $order->getIncrementId(),
            'order_date' => $order->getCreatedAt(),
            'sent_to_e1' => ($this->isSentToE1($scenario) && empty($shippedItems)),
            'not_send_to_e1' => !$this->isSentToE1($scenario),
            'is_delivered' => ($this->areShippedItemsDelivered($order, $shippedItems) && !empty($shippedItems)),
            'not_delivered' => (!$this->areShippedItemsDelivered($order, $shippedItems) && !empty($shippedItems)),
            'support_mail' => $this->scopeConfig->getValue(
                'trans_email/ident_support/email',
                ScopeInterface::SCOPE_STORE,
                $storeId
            ),
            'link' => $this->scopeConfig->getValue(
                'online_store/guide/faq',
                ScopeInterface::SCOPE_STORE,
                $storeId
            )."#shipping-return/",
        ];

        $this->inlineTranslation->suspend();
        try {
            $transport = $this->transportBuilder
                ->setTemplateIdentifier($template)
                ->setTemplateOptions([
                    'area' => Area::AREA_FRONTEND,
                    'store' => $storeId,
                ])
                ->setTemplateVars($templateVars)
                ->setFromByScope($senderInfo, $storeId)
                ->addTo($customerEmail, (string)$order->getCustomerName());

            if (!empty($copyTo) && $copyMethod === 'bcc') {
                foreach ($copyTo as $bccEmail) {
                    $transport->addBcc($bccEmail);
                }
            }

            $transport->getTransport()->sendMessage();

            if (!empty($copyTo) && $copyMethod === 'copy') {
                foreach ($copyTo as $copyEmail) {
                    $this->transportBuilder
                        ->setTemplateIdentifier($template)
                        ->setTemplateOptions([
                            'area' => Area::AREA_FRONTEND,
                            'store' => $storeId,
                        ])
                        ->setTemplateVars($templateVars)
                        ->setFromByScope($senderInfo, $storeId)
                        ->addTo($copyEmail)
                        ->getTransport()
                        ->sendMessage();
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'Failed to send order withdrawal confirmation email: ' . $e->getMessage(),
                ['order_id' => $order->getIncrementId()]
            );
            return false;
        } finally {
            $this->inlineTranslation->resume();
        }

        return true;
    }

    /**
     * Check if shipped Items are delivered
     *
     * @param Order $order
     * @param array $shippedItems
     * @return boolean
     */
    private function areShippedItemsDelivered(Order $order, array $shippedItems) :bool
    {
        if (empty($shippedItems)) {
            return false;
        }

        $shipmentOrderItemIds = array_column($shippedItems, 'order_item_id');

        $shipmentCollection = $this->shipmentCollectionFactory->create()->addFieldToFilter('order_id', $order->getEntityId());

        if ($shipmentCollection->getSize() === 0) {
            $this->logger->info(sprintf(
                'CheckShipmentFlag: No shipments found for order #%s (Order ID: %d)',
                $order->getIncrementId(),
                $order->getEntityId()
            ));
        }
            // Track which order item IDs have been verified with ship_flag = 1
            $flaggedOrderItemIds = [];

            foreach ($shipmentCollection as $shipment) {
                foreach ($shipment->getAllItems() as $shipmentItem) {

                    $orderItemId = (int) $shipmentItem->getOrderItemId();

                    // Only check items that are in the order
                    if (!in_array($orderItemId, $shipmentOrderItemIds)) {
                        continue;
                    }

                    $this->logger->info(sprintf(
                        'CheckShipmentFlag: Shipment #%s Item (order_item_id: %d) ship_flag = %s',
                        $shipment->getIncrementId(),
                        $orderItemId,
                        $shipmentItem->getShipFlag()
                    ));

                    if ((int) $shipment->getData('delivery_send_email') !== 1) {
                        return false;
                    }

                    $flaggedOrderItemIds[] = $orderItemId;
                }
            }

            // Ensure every RMA item was actually found and verified in shipments
            $unverifiedItems = array_diff($shipmentOrderItemIds, $flaggedOrderItemIds);

            if (!empty($unverifiedItems)) {
                $this->logger->info(sprintf(
                    'CheckShipmentFlag: RMA #%s has items not found in any shipment: %s',
                    $rma->getIncrementId(),
                    implode(', ', $unverifiedItems)
                ));
                return false;
            }
            return true;
    }

    /**
     * Check if order is sent to E1
     *
     * @param integer $scenario
     * @return boolean
     */
    public function isSentToE1(int $scenario) :bool
    {
        if ($scenario === WithdrawalHelper::SCENARIO_SENT_TO_E1) {
            return true;
        }

        return false;
    }

    /**
     * Resolve sender identity (e.g. support, sales) into name/email pair
     *
     * @param int $storeId
     * @return array{name: string, email: string}
     */
    private function getSenderInfo(int $storeId): array
    {
        $sender = $this->withdrawalHelper->getPianoEmailConfig(
            WithdrawalHelper::XML_PATH_SUBMISSION_EMAIL_SENDER,
            $storeId
        ) ?: 'support';

        return [
            'name' => (string)$this->scopeConfig->getValue(
                'trans_email/ident_' . $sender . '/name',
                ScopeInterface::SCOPE_STORE,
                $storeId
            ),
            'email' => (string)$this->scopeConfig->getValue(
                'trans_email/ident_' . $sender . '/email',
                ScopeInterface::SCOPE_STORE,
                $storeId
            ),
        ];
    }

    /**
     * Parse comma-separated copy-to emails
     *
     * @param int $storeId
     * @return string[]
     */
    private function getCopyToList(int $storeId): array
    {
        $raw = $this->withdrawalHelper->getPianoEmailConfig(
            WithdrawalHelper::XML_PATH_SUBMISSION_EMAIL_COPY_TO,
            $storeId
        );
        if (empty($raw)) {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $raw)));
    }
}
