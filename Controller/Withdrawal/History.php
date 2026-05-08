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

namespace CasioEMEA\Withdrawal\Controller\Withdrawal;

use Magento\Framework\App\Action\HttpGetActionInterface;

class History extends \Magento\Rma\Controller\Returns implements HttpGetActionInterface
{
  /**
     * Customer withdraw history
     *
     * @return false|null
     */
    public function execute()
    {
        if (!$this->_isEnabledOnFront()) {
            $this->_forward('noroute');
            return false;
        }

        $this->_view->loadLayout();
        $this->_view->getPage()->getConfig()->getTitle()->set(__('Withdrawals / Returns'));

        if ($block = $this->_view->getLayout()->getBlock('customer.account.link.back')) {
            $block->setRefererUrl($this->_redirect->getRefererUrl());
        }
        $this->_view->renderLayout();
    }
}