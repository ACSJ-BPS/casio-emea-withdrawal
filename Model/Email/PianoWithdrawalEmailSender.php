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

/**
 * Sends the "Withdrawal submission emails piano" transactional email to the customer
 * when a piano order withdrawal is submitted.
 */
class PianoWithdrawalEmailSender
{
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WithdrawalHelper $withdrawalHelper,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Send piano withdrawal submission email
     *
     * @param Order $order
     * @return bool
     */
    public function send(Order $order): bool
    {
        $storeId = (int)$order->getStoreId();

        if (!$this->withdrawalHelper->isPianoWithdrawalEmailEnabled($storeId)) {
            return false;
        }

        $customerEmail = $order->getCustomerEmail();
        if (empty($customerEmail)) {
            return false;
        }

        $template = $this->withdrawalHelper->getPianoEmailConfig(
            WithdrawalHelper::XML_PATH_PIANO_EMAIL_TEMPLATE,
            $storeId
        );
        if (empty($template)) {
            return false;
        }

        $senderInfo = $this->getSenderInfo($storeId);
        $copyTo = $this->getCopyToList($storeId);
        $copyMethod = $this->withdrawalHelper->getPianoEmailConfig(
            WithdrawalHelper::XML_PATH_PIANO_EMAIL_COPY_METHOD,
            $storeId
        );

        $templateVars = [
            'customer_name' => $order->getCustomerName(),
            'order_increment_id' => $order->getIncrementId(),
            'order_date' => $order->getCreatedAt(),
            'support_mail' => $this->scopeConfig->getValue(
                'trans_email/ident_support/email',
                ScopeInterface::SCOPE_STORE,
                $storeId
            ),
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
                'Failed to send piano withdrawal submission email: ' . $e->getMessage(),
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
            WithdrawalHelper::XML_PATH_PIANO_EMAIL_SENDER,
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
            WithdrawalHelper::XML_PATH_PIANO_EMAIL_COPY_TO,
            $storeId
        );
        if (empty($raw)) {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $raw)));
    }
}
