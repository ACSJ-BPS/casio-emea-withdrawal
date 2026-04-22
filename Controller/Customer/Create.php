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

namespace CasioEMEA\Withdrawal\Controller\Customer;

use CasioEMEA\Withdrawal\Helper\Data as WithdrawConfig;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context as HelperContext;
use Magento\Framework\Controller\ResultFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Rma\Controller\Returns as RmaReturns;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Registry as CoreRegistry;

class Create extends RmaReturns implements HttpGetActionInterface
{
    
    /**
     * Constructor for the Create controller
     * @param Context $context
     * @param ResultFactory $resultFactory
     * @param CustomerSession $customerSession
     * @param ScopeConfigInterface $scopeConfig
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        Context $context,
        ResultFactory $resultFactory,
        CoreRegistry $coreRegistry,
        private readonly CustomerSession $customerSession,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
        parent::__construct($context, $coreRegistry);
    }

    /**
     * Execute the controller action to create a withdrawal request
     *
     * @return void
     */
    public function execute() :void
    {
        $orderId = (int)$this->getRequest()->getParam('order_id');
        if (empty($orderId)) {
            $this->_redirect('sales/order/history');
            return;
        }

        if (!$this->scopeConfig->isSetFlag(WithdrawConfig::XML_PATH_WITHDRAWAL_ENABLED)) {
            $this->_redirect('sales/order/history');
            return;
        }

        if (!$this->customerSession->isLoggedIn()) {
            $this->_redirect('customer/account/login');
            return;
        }

        /** @var \Magento\Sales\Api\Data\OrderInterface $order */
        $order = $this->orderRepository->get($orderId);

        if (!$this->_canViewOrder($order)) {
            $this->_redirect('sales/order/history');
            return;
        }

        $this->_coreRegistry->register('current_order', $order);

        // if (!$this->_loadOrderItems($orderId)) {
        //     return;
        // }

        $this->_view->loadLayout();

        $this->_view->getPage()->getConfig()->getTitle()->set(__('Withdrawal Form'));
        if ($block = $this->_view->getLayout()->getBlock('customer.account.link.back')) {
            $block->setRefererUrl($this->_redirect->getRefererUrl());
        }
        $this->_view->renderLayout();
    }
}