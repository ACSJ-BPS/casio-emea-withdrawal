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

namespace CasioEMEA\Withdrawal\Controller\Guest;

use CasioEMEA\Withdrawal\Helper\Data as WithdrawConfig;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context as HelperContext;
use Magento\Framework\Controller\ResultFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Rma\Controller\Guest;
use Magento\Framework\Registry as CoreRegistry;
use Magento\Rma\Helper\Data as RmaHelper;
use Magento\Sales\Helper\Guest as SalesGuestHelper;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\View\Result\LayoutFactory;
use Magento\Framework\Controller\Result\ForwardFactory;

class Create extends Guest implements HttpGetActionInterface
{
    /**
     * Constructor for the Create controller
     * @param Context $context
     * @param CoreRegistry $coreRegistry
     * @param RmaHelper $rmaHelper
     * @param SalesGuestHelper $salesGuestHelper
     * @param PageFactory $resultPageFactory
     * @param LayoutFactory $resultLayoutFactory
     * @param ForwardFactory $resultForwardFactory
     */
    public function __construct(
        Context $context,
        CoreRegistry $coreRegistry,
        RmaHelper $rmaHelper,
        SalesGuestHelper $salesGuestHelper,
        PageFactory $resultPageFactory,
        LayoutFactory $resultLayoutFactory,
        ForwardFactory $resultForwardFactory,
        private readonly ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct($context, $coreRegistry, $rmaHelper, $salesGuestHelper, $resultPageFactory, $resultLayoutFactory, $resultForwardFactory);
    }

    /**
     * Execute the controller action to create a withdrawal request
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        if (!$this->scopeConfig->isSetFlag(WithdrawConfig::XML_PATH_WITHDRAWAL_ENABLED)) {
            return $this->resultRedirectFactory->create()->setPath('sales/order/history');
        }

        $result = $this->salesGuestHelper->loadValidOrder($this->_request);
        if ($result instanceof \Magento\Framework\Controller\ResultInterface) {
            return $result;
        }
        $order = $this->_coreRegistry->registry('current_order');
        $orderId = $order->getId();
        // if (!$this->_loadOrderItems($orderId)) {
        //     return $this->resultRedirectFactory->create()->setPath('sales/order/history');
        // }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->set(__('Withdrawal Form'));
        if ($block = $resultPage->getLayout()->getBlock('customer.account.link.back')) {
            $block->setRefererUrl($this->_redirect->getRefererUrl());
        }

        return $resultPage;
    }
}