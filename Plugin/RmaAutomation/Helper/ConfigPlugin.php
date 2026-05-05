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

namespace CasioEMEA\Withdrawal\Plugin\RmaAutomation\Helper;

use CasioEMEA\RmaAutomation\Helper\Config;
use Magento\Rma\Api\Data\RmaInterface;
use CasioEMEA\Withdrawal\Helper\Data as WithdrawalHelper;

class ConfigPlugin
{
    /**
     * Constructor
     *
     * @param WithdrawalHelper $withdrawalHelper
     */
    public function __construct(
        private readonly WithdrawalHelper $withdrawalHelper
    ) {
    }
    /**
     * Alter result if Withdrawal is enabled
     *
     * @param Config $subject
     * @param RmaInterface $rma
     * @param boolean $result
     * @return boolean
     */
    public function afterCanSendRmaEmail(Config $subject, RmaInterface $rma, bool $result) :bool
    {
        if ($this->withdrawalHelper->isEnabled((int)$rma->getStoreId())) {
            return false;
        }
        return $result;
    }
}