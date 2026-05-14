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
use Magento\Rma\Api\RmaRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Sales\Model\Order\Address\Renderer as AddressRenderer;
use Magento\Rma\Helper\Data as RmaHelper;
use Magento\Sales\Model\ResourceModel\Order\Shipment\CollectionFactory as ShipmentCollectionFactory;

/**
 * Sends the "Withdrawal submission emails piano" transactional email to the customer
 * when a piano order withdrawal is submitted.
 */
class PianoWithdrawalConfirmationEmailSender
{
    /**
     * Constructor
     *
     * @param TransportBuilder $transportBuilder
     * @param StateInterface $inlineTranslation
     * @param ScopeConfigInterface $scopeConfig
     * @param WithdrawalHelper $withdrawalHelper
     * @param LoggerInterface $logger
     * @param RmaRepositoryInterface $rmaRepository
     * @param StoreManagerInterface $storeManager
     * @param AddressRenderer $addressRenderer
     * @param RmaHelper $rmaHelper
     * @param ShipmentCollectionFactory $shipmentCollectionFactory
     */
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WithdrawalHelper $withdrawalHelper,
        private readonly LoggerInterface $logger,
        private readonly RmaRepositoryInterface $rmaRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly AddressRenderer $addressRenderer,
        private readonly RmaHelper $rmaHelper,
        private ShipmentCollectionFactory $shipmentCollectionFactory
    ) {
    }

    /**
     * Send order withdrawal confirmation email
     *
     * @param Order $order
     * @param integer $rmaId
     * @param integer $scenario
     * @return boolean
     */
    public function send(Order $order, int $rmaId, int $scenario = 0): bool
    {
        $storeId = (int)$order->getStoreId();

        if (!$this->withdrawalHelper->isPianoWithdrawalConfirmationEmailEnabled($storeId)) {
            return false;
        }

        $customerEmail = $order->getCustomerEmail();
        if (empty($customerEmail)) {
            return false;
        }

        $template = $this->withdrawalHelper->getPianoEmailConfig(
            WithdrawalHelper::XML_PATH_CONFIRMATION_PIANO_EMAIL_TEMPLATE,
            $storeId
        );
        if (empty($template)) {
            return false;
        }

        $senderInfo = $this->getSenderInfo($storeId);
        $copyTo = $this->getCopyToList($storeId);
        $copyMethod = $this->withdrawalHelper->getPianoEmailConfig(
            WithdrawalHelper::XML_PATH_CONFIRMATION_EMAIL_COPY_METHOD,
            $storeId
        );
        

        $templateVars = $this->getRmaDetails($order, (int)$rmaId, $storeId, $scenario);

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
                'Failed to send order withdrawal submission email: ' . $e->getMessage(),
                ['order_id' => $order->getIncrementId()]
            );
            return false;
        } finally {
            $this->inlineTranslation->resume();
        }

        return true;
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
            WithdrawalHelper::XML_PATH_CONFIRMATION_PIANO_EMAIL_SENDER,
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
            WithdrawalHelper::XML_PATH_CONFIRMATION_PIANO_EMAIL_COPY_TO,
            $storeId
        );
        if (empty($raw)) {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $raw)));
    }

    /**
     * Return Rma details
     *
     * @param Order $order
     * @param int $rmaId
     * @param int $storeId
     * @param int $scenario
     * @return array 
     */
    private function getRmaDetails(Order $order, int $rmaId, int $storeId, int $scenario) :array
    {
        $rmaDetails = [];

        $rma = $this->rmaRepository->get($rmaId);
        $store = $this->storeManager->getStore($storeId);
        $returnAddress = $this->rmaHelper->getReturnAddress('html', [], $storeId);
        if ($rma) {
           $rmaDetails = [
                'rma' => $rma,
                'rma_id' => $rma->getId(),
                'rma_data' => [
                                'status_label' => is_string($rma->getStatusLabel()) ?
                                    $rma->getStatusLabel() : $rma->getStatusLabel()->render(),
                            ],
                'order' => $order,
                'order_data' => [
                                'customer_name' => $order->getCustomerName(),
                            ],
                'created_at_formatted_1' => $rma->getCreatedAtFormated(1),
                'store' => $store,
                'customer_name' => $order->getCustomerName(),
                'order_increment_id' => $order->getIncrementId(),
                'return_address' => $returnAddress,
                'item_collection' => $rma->getItemsForDisplay(),
                'formattedShippingAddress' => $this->addressRenderer->format(
                                $order->getShippingAddress(),
                                'html'
                            ),
                'formattedBillingAddress' => $this->addressRenderer->format(
                                $order->getBillingAddress(),
                                'html'
                            ),
                'supportEmail' => $store->getConfig('trans_email/ident_support/email'),
                'storePhone' => $store->getConfig('general/store_information/phone'),
                'link' => $this->scopeConfig->getValue(
                                'online_store/guide/faq',
                                ScopeInterface::SCOPE_STORE,
                                $storeId
                            )."#shipping-return/",
            ];
        }
        return $rmaDetails;
    }
}
