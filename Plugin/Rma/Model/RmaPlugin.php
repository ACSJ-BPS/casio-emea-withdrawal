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

namespace CasioEMEA\Withdrawal\Plugin\Rma\Model;

use Magento\Rma\Model\Rma as CoreRma;
use CasioEMEA\Withdrawal\Helper\Data as WithdrawalHelper;
use Magento\Rma\Model\Rma\Status\HistoryFactory;
use Magento\Rma\Model\Rma\Status\History;
use CasioEMEA\CabinetPiano\Helper\Data as CabinetPianoHelper;
use CasioEMEA\Withdrawal\Model\Email\PianoWithdrawalConfirmationEmailSender;

class RmaPlugin
{
    /**
     * Constructor
     *
     * @param WithdrawalHelper $withdrawalHelper
     * @param HistoryFactory $statusHistoryFactory
     * @param CabinetPianoHelper $cabinetPianoHelper
     * @param PianoWithdrawalConfirmationEmailSender $pianoWithdrawalConfirmationEmailSender
     */
    public function __construct(
        private readonly WithdrawalHelper $withdrawalHelper,
        private readonly HistoryFactory $statusHistoryFactory,
        private readonly CabinetPianoHelper $cabinetPianoHelper,
        private readonly PianoWithdrawalConfirmationEmailSender $pianoWithdrawalConfirmationEmailSender
    ) {
    }

    /**
     * Send Confirmatiom Email
     *
     * @param CoreRma $subject
     * @param boolean|CoreRma $result
     * @param array $data
     * @return boolean|CoreRma
     */
    public function afterSaveRma(CoreRma $subject, bool|CoreRma $result, array $data) :bool|CoreRma
    {
        if ($this->withdrawalHelper->isEnabled((int)$subject->getStoreId()) && $this->cabinetPianoHelper->checkPianoItems($subject->getOrder())) {
            $statusHistory = $this->statusHistoryFactory->create();
            $this->pianoWithdrawalConfirmationEmailSender->send($subject->getOrder(), (int)$subject->getEntityId());
        }
        return $result;
    }
}