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

use Magento\Rma\Controller\Returns\Returns;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Rma\Api\Data\RmaInterface;
use Magento\Rma\Model\Rma;
use Magento\Rma\Model\Rma\Status\HistoryFactory;
use Magento\Rma\Model\RmaFactory;
use Magento\Sales\Model\OrderRepository;
use Magento\Rma\Model\Rma\Source\Status;
use Magento\Rma\Model\Rma\Status\History;
use Psr\Log\LoggerInterface;
use Magento\Sales\Model\Order;
use Magento\Rma\Helper\Data;
use CasioEMEA\Withdrawal\Service\CreateWithdrawalCreditmemoService;
use CasioEMEA\Withdrawal\Service\SetWithdrawalFlagForOrdersSentToE1NotShippedService;
use CasioEMEA\Withdrawal\Helper\Data as WithdrawalHelper;
use Magento\Framework\Filter\FilterManager;
use Casio\RmaAutomation\Helper\Config as RmaAutomationHelper;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Throwable;
use Exception;

/**
 * Controller class Withdraw. Contains logic of request, responsible for withdrawal creation
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Withdraw extends Returns implements HttpPostActionInterface
{
    /**
     * @var RmaFactory
     */
    private $rmaModelFactory;

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @var HistoryFactory
     */
    private $statusHistoryFactory;

    /**
     * @var Data
     */
    private $rmaHelper;

    /**
     * Withdraw constructor.
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Framework\Registry $coreRegistry
     * @param RmaFactory $rmaModelFactory
     * @param OrderRepository $orderRepository
     * @param LoggerInterface $logger
     * @param DateTime $dateTime
     * @param HistoryFactory $statusHistoryFactory
     * @param CreateWithdrawalCreditmemoService $createWithdrawalCreditmemoService
     * @param SetWithdrawalFlagForOrdersSentToE1NotShippedService $setWithdrawalFlagService
     * @param WithdrawalHelper $withdrawalHelper
     * @param FilterManager $filterManager
     * @param RmaAutomationHelper $rmaAutomationHelper
     * @param StoreManagerInterface $storeManager
     * @param CustomerSession $customerSession
     * @param RedirectInterface $redirect
     * @param Data|null $rmaHelper
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\Registry $coreRegistry,
        RmaFactory $rmaModelFactory,
        OrderRepository $orderRepository,
        LoggerInterface $logger,
        DateTime $dateTime,
        HistoryFactory $statusHistoryFactory,
        private readonly CreateWithdrawalCreditmemoService $createWithdrawalCreditmemoService,
        private readonly SetWithdrawalFlagForOrdersSentToE1NotShippedService $setWithdrawalFlagService,
        private readonly WithdrawalHelper $withdrawalHelper,
        private readonly FilterManager $filterManager,
        private readonly RmaAutomationHelper $rmaAutomationHelper,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerSession $customerSession,
        private readonly RedirectInterface $redirect,
        private readonly OrderItemRepositoryInterface $orderItemRepository,
        ?Data $rmaHelper = null
    ) {
        $this->rmaModelFactory = $rmaModelFactory;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger;
        $this->dateTime = $dateTime;
        $this->statusHistoryFactory = $statusHistoryFactory;
        parent::__construct($context, $coreRegistry);

        $this->rmaHelper = $rmaHelper ?: $this->_objectManager->create(Data::class);
    }

    /**
     * Goods return requests entrypoint
     */
    public function execute()
    {
        $simplefields = [
            'order_item_id',
            'qty_requested',
            'condition',
            'reason'
        ];
        $orderId = (int)$this->getRequest()->getParam('order_id');
        $post = $this->getRequest()->getPostValue();

        $order = $this->orderRepository->get($orderId);

        // If order is not sent to E1, we can directly create credit memo without creating RMA
        if ($this->withdrawalHelper->orderNotSentToE1($order)) {
            try {
                $fullOrderWithdrawal = isset($post['withdrawal_checkbox']) && (int)$post['withdrawal_checkbox'] === 1 ? true : false;
                $fullWithdrawalReason =  (isset($post["withdrawal_reason_full_order"]) && $post['withdrawal_reason_full_order']) ? $post['withdrawal_reason_full_order'] : "0";
                $withdrawalItems = isset($post['items']) ? $post['items'] : [];
                $this->createWithdrawalCreditmemoService->execute($order, $fullOrderWithdrawal, $withdrawalItems, $fullWithdrawalReason);
                $this->messageManager->addSuccessMessage(__('Your withdrawal request for order #%1 has been submitted successfully.', $order->getIncrementId()));
                $this->_redirect('sales/order/history');
                return;
            } catch (\Exception $e) {
                $this->logger->critical('Error creating credit memo for withdrawal: ' . $e->getMessage());
                $this->messageManager->addErrorMessage(__('We can\'t process withdrawal request for this order #%1 right now. Please try again later.', $order->getIncrementId()));
                $this->_redirect('sales/order/history');
                return;
            }
        }

        // If order is sent to E1 but not shipped, we will set withdrawal flags without creating RMA and credit memo, and the merchant will process the withdrawal in E1 based on the flags. The RMA will be created after the order is shipped.
        if ($this->withdrawalHelper->orderSentToE1NotShipped($order)) {
            try {
                $fullOrderWithdrawal = isset($post['withdrawal_checkbox']) && (int)$post['withdrawal_checkbox'] === 1 ? true : false;
                $fullWithdrawalReason =  (isset($post["withdrawal_reason_full_order"]) && $post['withdrawal_reason_full_order']) ? $post['withdrawal_reason_full_order'] : "0";
                $withdrawalItems = isset($post['items']) ? $post['items'] : [];
                $this->setWithdrawalFlagService->execute($order, $fullOrderWithdrawal, $withdrawalItems, $fullWithdrawalReason);
                $this->messageManager->addSuccessMessage(__('Your withdrawal request for order #%1 has been submitted successfully. The RMA will be created after the order is shipped.', $order->getIncrementId()));
                $this->_redirect('sales/order/history');
                return;
            } catch (\Exception $e) {
                $this->logger->critical('Error setting withdrawal flag for order sent to E1 but not shipped: ' . $e->getMessage());
                $this->messageManager->addErrorMessage(__('We can\'t process withdrawal request for this order #%1 right now. Please try again later.', $order->getIncrementId()));
                $this->_redirect('sales/order/history');
                return;
            }
            $this->messageManager->addErrorMessage(__('Withdrawal request cannot be created for order #%1 because it has not been fully invoiced yet.', $order->getIncrementId()));
            $this->_redirect('sales/order/history');
            return;
        }

        if (!$this->rmaHelper->canCreateRma($orderId)) {
            return $this->resultRedirectFactory->create()->setPath('withdrawal/customer/create', ['order_id' => $orderId]);
        }

        // Validate input and create RMA
        if ($post) {
            $withdrawnStatus = WithdrawalHelper::ORDER_NOT_WITHDRAWN;
            $fullOrderWithdrawal = isset($post['withdrawal_checkbox']) && (int)$post['withdrawal_checkbox'] === 1 ? true : false;
            $isOrderFullyWithdrawn = true;
            
            /**
             * If it's a full order withdrawal, we will set the requested qty for all items to be the remaining refundable qty and set the status to fully withdrawn.
             * If it's not a full order withdrawal, we will validate the input qty for each item and set the status to partially withdrawn for items that are being withdrawn. The order will be considered fully
             * withdrawn only if all items are being fully withdrawn, otherwise it will be partially withdrawn. This is to ensure that the order status is consistent with the item statuses and to avoid confusion for the customer and the merchant.
             */
            if ($fullOrderWithdrawal) {
                foreach ($order->getAllItems() as $orderItem) {
                    if ($orderItem->isDummy()) {
                        continue;
                    }
                    $itemsToReturn[] = [
                        'order_item_id'      => $orderItem->getId(),
                        'qty_requested'      => (string)$orderItem->getQtyToRefund(),
                        'condition'  => "0",
                        'reason'  => $post["withdrawal_reason_full_order"] ?? "0"
                    ];
                }
                $post['items'] = $itemsToReturn;
                $orderStatusTobeSet = $order->getStatus();
                $orderComment = 'This Order was fully withdrawn by the customer.';
                $withdrawnStatus = WithdrawalHelper::ORDER_FULLY_WITHDRAWN;
            }
            foreach ($post['items'] as $key => $item) {
                foreach ($simplefields as $simplefield) {
                    $paramValue = $item[$simplefield];
                    if (!empty($paramValue)) {
                        $filteredSimValue = $this->filterManager->stripTags($paramValue);
                        if ($paramValue !== $filteredSimValue) {
                            $error = __('HTML tags are not allowed.');
                        }
                    }
                }

                $post['items'][$key]['qty_authorized'] = $item['qty_requested'];
                $post['items'][$key]['status'] = $this->getStatus();
                $orderItem = $this->orderItemRepository->get((int)$item['order_item_id']);
                if ($withdrawnStatus === WithdrawalHelper::ORDER_FULLY_WITHDRAWN) {
                    $orderItem->setData('withdrawal_item_status', WithdrawalHelper::ITEM_FULLY_WITHDRAWN);
                } else {
                    if ($orderItem->getQtyOrdered() === $orderItem->getQtyRefunded() + $item['qty_requested']) {
                        $orderItem->setData('withdrawal_item_status', WithdrawalHelper::ITEM_FULLY_WITHDRAWN);
                    } else {
                        $orderItem->setData('withdrawal_item_status', WithdrawalHelper::ITEM_PARTIALLY_WITHDRAWN);
                        $isOrderFullyWithdrawn = false;
                    }
                }
                $this->orderItemRepository->save($orderItem);
            }

            // Set order withdrawal status based on whether it's a full order withdrawal or if all items are fully withdrawn
            $withdrawnStatus = $fullOrderWithdrawal ? WithdrawalHelper::ORDER_FULLY_WITHDRAWN : WithdrawalHelper::ORDER_PARTIALLY_WITHDRAWN;

            if (!empty($error)) {
                $this->messageManager->addErrorMessage(
                    __($error)
                );
                return $this->resultRedirectFactory->create()->setPath('sales/order/history');
            }

            try {
                /** @var Order $order */
                $order = $this->orderRepository->get($orderId);

                if (!$this->canViewOrder($order)) {
                    return $this->redirect('sales/order/history');
                }

                /** @var Rma $rmaObject */
                $rmaObject = $this->buildRma($order, $post);
                if (!$rmaObject->saveRma($post)) {
                    $url = $this->url->getUrl('*/*/create', ['order_id' => $orderId]);
                    return $this->getResponse()->setRedirect($this->redirect->error($url));
                }

                $statusHistory = $this->statusHistoryFactory->create();
                $statusHistory->setRmaEntityId($rmaObject->getEntityId());
                $statusHistory->sendNewRmaEmail();
                $statusHistory->saveSystemComment();

                if (isset($post['rma_comment']) && !empty($post['rma_comment'])) {
                    /** @var History $comment */
                    $comment = $this->statusHistoryFactory->create();
                    $comment->setRmaEntityId($rmaObject->getEntityId());
                    $comment->saveComment($post['rma_comment'], true, false);
                }

                $order->setStatus($orderStatusTobeSet);
                $order->setData('withdrawal_order_status', $withdrawnStatus);
                $order->addCommentToStatusHistory(
                    $orderComment,
                    $order->getStatus(),                            
                    true                                             
                );

                $this->orderRepository->save($order);

                $this->messageManager->addSuccessMessage(
                    __(
                        'You submitted Return #%1.',
                        $rmaObject->getIncrementId()
                    )
                );
                return $this->resultRedirectFactory->create()->setPath('rma/returns/history');
            } catch (Throwable $e) {
                $this->messageManager->addErrorMessage(
                    __('We can\'t create a return right now. Please try again later.')
                );

                $this->logger->critical($e->getMessage());
                return $this->resultRedirectFactory->create()->setPath('sales/order/history');
            }
        } else {
            return $this->resultRedirectFactory->create()->setPath('sales/order/history');
        }
    }

    /**
     * Triggers save order and create history comment process
     *
     * @param Order $order
     * @param array $post
     * @return RmaInterface
     */
    private function buildRma(Order $order, array $post): RmaInterface
    {
        /** @var RmaInterface $rmaModel */
        $rmaModel = $this->rmaModelFactory->create();

        $rmaModel->setData(
            [
                'status' => Status::STATE_PENDING,
                'date_requested' => $this->dateTime->gmtDate(),
                'order_id' => $order->getId(),
                'order_increment_id' => $order->getIncrementId(),
                'store_id' => $order->getStoreId(),
                'customer_id' => $order->getCustomerId(),
                'order_date' => $order->getCreatedAt(),
                'customer_name' => $order->getCustomerName(),
                'customer_custom_email' => isset($post['customer_custom_email']) ? $post['customer_custom_email'] : '',
            ]
        );

        return $rmaModel;
    }

    /**
     * Get RMA Status
     *
     * @return string
     */
    private function getStatus(): string
    {
        try {
            if ($this->rmaAutomationHelper->isAutoAuthorize($this->storeManager->getWebsite()->getId())) {
                $status = Status::STATE_AUTHORIZED;
            } else {
                $status = Status::STATE_PENDING;
            }
        } catch (Exception $exception) {
            $this->logger->critical($exception->getMessage());
            $status = null;
        }
        return $status;
    }

    /**
     * Check order view availability
     *
     * @param Rma|Order $item
     * @return bool
     */
    private function canViewOrder($item): bool
    {
        $customerId = $this->customerSession->getId();
        if ($item->getId() && $customerId && $item->getCustomerId() == $customerId) {
            return true;
        }
        return false;
    }
}
