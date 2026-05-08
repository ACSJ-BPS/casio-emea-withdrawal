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
use CasioEMEA\CabinetPiano\ViewModel\PianoDetails;
use CasioEMEA\Withdrawal\Model\Email\PianoWithdrawalEmailSender;
use CasioEMEA\Withdrawal\Model\Email\WithdrawalSubmissionEmailSender;
use CasioEMEA\Withdrawal\Model\Email\WithdrawalConfirmationEmailSender;

/**
 * Controller class Withdraw. Contains logic of request, responsible for withdrawal creation
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Customerwithdraw extends Returns implements HttpPostActionInterface
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
     * Constructor
     *
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
     * @param PianoDetails $pianoViewModel
     * @param PianoWithdrawalEmailSender $pianoWithdrawalEmailSender
     * @param OrderItemRepositoryInterface $orderItemRepository
     * @param WithdrawalConfirmationEmailSender $withdrawalConfirmationEmailSender
     * @param WithdrawalSubmissionEmailSender $withdrawalSubmissionEmailSender
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
        private readonly PianoDetails $pianoViewModel,
        private readonly PianoWithdrawalEmailSender $pianoWithdrawalEmailSender,
        private readonly OrderItemRepositoryInterface $orderItemRepository,
        private readonly WithdrawalConfirmationEmailSender $withdrawalConfirmationEmailSender,
        private readonly WithdrawalSubmissionEmailSender $withdrawalSubmissionEmailSender,
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
        $isPianoOrder = $this->pianoViewModel->isPianoOrder($order);
        if ($isPianoOrder) {
            try {
                $order->setStatus(WithdrawalHelper::PIANO_ORDER_STATUS);
                $order->addCommentToStatusHistory(
                    __('Withdrawal request submitted by customer')->render(),
                    WithdrawalHelper::PIANO_ORDER_STATUS,
                    false
                );
                $this->orderRepository->save($order);

                $this->pianoWithdrawalEmailSender->send($order);
                $this->messageManager->addSuccessMessage(
                    __(
                        'Your withdrawal request for order #%1 has been submitted.',
                        $order->getIncrementId()
                    )
                );
            } catch (\Throwable $e) {
                $this->logger->critical(
                    'Error saving withdrawal request for order #%1: ' . $e->getMessage(),
                    ['order_id' => $orderId]
                );
            }

            $this->_redirect('sales/order/view', ['order_id' => $orderId]);
            return;
        }

        // If order is not sent to E1, we can directly create credit memo without creating RMA
        if ($this->withdrawalHelper->orderNotSentToE1($order)) {
            try {
                $fullOrderWithdrawal = isset($post['withdrawal_checkbox']) && (int)$post['withdrawal_checkbox'] === 1 ? true : false;
                $fullWithdrawalReason =  (isset($post["withdrawal_reason_full_order"]) && $post['withdrawal_reason_full_order']) ? $post['withdrawal_reason_full_order'] : "0";
                $withdrawalItems = isset($post['items']) ? $post['items'] : [];
                $fullWithdrawalReasonOther = isset($post['full_withdrawal_reason_other']) ? $post['full_withdrawal_reason_other'] : "";
                $this->createWithdrawalCreditmemoService->execute($order, $fullOrderWithdrawal, $withdrawalItems, $fullWithdrawalReason, $fullWithdrawalReasonOther);
                $this->withdrawalSubmissionEmailSender->send($order, WithdrawalHelper::SCENARIO_NOT_SENT_TO_E1);
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

        $shippedItems = [];

        // If order is sent to E1 but not shipped, we will set withdrawal flags without creating RMA and credit memo, and the merchant will process the withdrawal in E1 based on the flags. The RMA will be created after the order is shipped.
        if ($this->withdrawalHelper->orderSentToE1($order)) {
            try {
                $fullOrderWithdrawal = isset($post['withdrawal_checkbox']) && (int)$post['withdrawal_checkbox'] === 1 ? true : false;
                $fullWithdrawalReason =  (isset($post["withdrawal_reason_full_order"]) && $post['withdrawal_reason_full_order']) ? $post['withdrawal_reason_full_order'] : "0";
                $withdrawalItems = isset($post['items']) ? $post['items'] : [];
                $fullWithdrawalReasonOther = isset($post['full_withdrawal_reason_other']) ? $post['full_withdrawal_reason_other'] : "";
                $shippedItems = $this->setWithdrawalFlagService->execute($order, $fullOrderWithdrawal, $withdrawalItems, $fullWithdrawalReason, $fullWithdrawalReasonOther);
                $this->withdrawalSubmissionEmailSender->send($order, WithdrawalHelper::SCENARIO_SENT_TO_E1, $shippedItems);
                if (empty($shippedItems)) {
                    $this->messageManager->addSuccessMessage(__('Your withdrawal request for order #%1 has been submitted successfully. The RMA will be created after the order is shipped.', $order->getIncrementId()));
                    $this->_redirect('sales/order/history');
                    return;
                }
            } catch (\Exception $e) {
                $this->logger->critical('Error setting withdrawal flag for order sent to E1 but not shipped: ' . $e->getMessage());
                $this->messageManager->addErrorMessage(__('We can\'t process withdrawal request for this order #%1 right now. Please try again later.', $order->getIncrementId()));
                $this->_redirect('sales/order/history');
                return;
            }
        }

        if (!$this->rmaHelper->canCreateRma($orderId)) {
            return $this->resultRedirectFactory->create()->setPath('sales/withdrawal/customer', ['order_id' => $orderId]);
        }

        // Validate input and create RMA
        if ($post) {
            $withdrawnStatus = WithdrawalHelper::ORDER_NOT_WITHDRAWN;
            $fullOrderWithdrawal = isset($post['withdrawal_checkbox']) && (int)$post['withdrawal_checkbox'] === 1 ? true : false;
            $isOrderFullyWithdrawn = true;
            $orderStatusTobeSet = $order->getStatus();
            $orderComment = 'This Order was fully withdrawn by the customer.';
            
            /**
             * If it's a full order withdrawal, we will set the requested qty for all items to be the remaining refundable qty and set the status to fully withdrawn.
             * If it's not a full order withdrawal, we will validate the input qty for each item and set the status to partially withdrawn for items that are being withdrawn. The order will be considered fully
             * withdrawn only if all items are being fully withdrawn, otherwise it will be partially withdrawn. This is to ensure that the order status is consistent with the item statuses and to avoid confusion for the customer and the merchant.
             */
            if ($fullOrderWithdrawal && !empty($shippedItems)) {
                foreach ($order->getAllItems() as $orderItem) {
                    if ($orderItem->isDummy()) {
                        continue;
                    }

                    if ((isset($post["full_withdrawal_reason_other"]) && $post['full_withdrawal_reason_other'])) {
                            $itemsToReturn[] = [
                                'order_item_id'      => $orderItem->getId(),
                                'qty_requested'      => (string)$orderItem->getQtyToRefund(),
                                'condition'  => "0",
                                'reason'  => (isset($post["withdrawal_reason_full_order"]) && $post['withdrawal_reason_full_order']) ? $post['withdrawal_reason_full_order'] : "0",
                                'reason_other' => $post['full_withdrawal_reason_other']
                            ];
                    } else {
                            $itemsToReturn[] = [
                                'order_item_id'      => $orderItem->getId(),
                                'qty_requested'      => (string)$orderItem->getQtyToRefund(),
                                'condition'  => "0",
                                'reason'  => (isset($post["withdrawal_reason_full_order"]) && $post['withdrawal_reason_full_order']) ? $post['withdrawal_reason_full_order'] : "0"
                            ];
                    }
                }
                $post['items'] = $itemsToReturn;
                $orderStatusTobeSet = $order->getStatus();
                $orderComment = 'Withdrawal request submitted by Customer.';
                $withdrawnStatus = WithdrawalHelper::ORDER_FULLY_WITHDRAWN;
            }

            $itemsToSave = [];
            $post['items'] = !empty($shippedItems) ? $this->getRemainingItemToWithdraw($post['items'], $shippedItems) : $post['items'];
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
                    $orderItem->setData(WithdrawalHelper::WITHDRAWAL_ITEM_KEY, WithdrawalHelper::ITEM_FULLY_WITHDRAWN);
                    $orderItem->setData(WithdrawalHelper::WITHDRAWAL_QTY_KEY, (int)($orderItem->getData(WithdrawalHelper::WITHDRAWAL_QTY_KEY) + (int)$item['qty_requested']));
                    $orderItem->setData(WithdrawalHelper::WITHDRAWAL_ITEM_REASON_KEY, (int)$item['reason']);
                } else {
                    if ((int)$orderItem->getQtyOrdered() === (int)$orderItem->getData(WithdrawalHelper::WITHDRAWAL_QTY_KEY) + (int)$item['qty_requested']) {
                        $orderItem->setData(WithdrawalHelper::WITHDRAWAL_ITEM_KEY, WithdrawalHelper::ITEM_FULLY_WITHDRAWN);
                    } else {
                        $orderItem->setData(WithdrawalHelper::WITHDRAWAL_ITEM_KEY, WithdrawalHelper::ITEM_PARTIALLY_WITHDRAWN);
                        $isOrderFullyWithdrawn = false;
                        $orderComment = 'Withdrawal request submitted by Customer.';
                    }

                    $orderItem->setData(WithdrawalHelper::WITHDRAWAL_QTY_KEY, (int)($orderItem->getData(WithdrawalHelper::WITHDRAWAL_QTY_KEY) + (int)$item['qty_requested']));
                    $orderItem->setData(WithdrawalHelper::WITHDRAWAL_ITEM_REASON_KEY, (int)$item['reason']);
                }
                $itemsToSave[] = $orderItem;
            }

            // Set order withdrawal status based on whether it's a full order withdrawal or if all items are fully withdrawn
            $withdrawnStatus = $isOrderFullyWithdrawn ? WithdrawalHelper::ORDER_FULLY_WITHDRAWN : WithdrawalHelper::ORDER_PARTIALLY_WITHDRAWN;

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
                $statusHistory->saveSystemComment();

                if (isset($post['rma_comment']) && !empty($post['rma_comment'])) {
                    /** @var History $comment */
                    $comment = $this->statusHistoryFactory->create();
                    $comment->setRmaEntityId($rmaObject->getEntityId());
                    $comment->saveComment($post['rma_comment'], true, false);
                }

                $order->setStatus($orderStatusTobeSet);
                $order->setData(WithdrawalHelper::WITHDRAWAL_ORDER_KEY, $withdrawnStatus);
                $order->addCommentToStatusHistory(
                    $orderComment,
                    $order->getStatus(),                            
                    true                                             
                );

                $order->setItems($itemsToSave);
                $this->orderRepository->save($order);
                $this->withdrawalConfirmationEmailSender->send($order, (int)$rmaObject->getEntityId(), WithdrawalHelper::SCENARIO_SENT_TO_E1);

                $this->messageManager->addSuccessMessage(
                    __(
                        'You submitted Return #%1.',
                        $rmaObject->getIncrementId()
                    )
                );
                return $this->resultRedirectFactory->create()->setPath('sales/withdrawal/history');
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

    /**
     * Get the list of items that are not shipped yet for orders that are sent to E1 but not shipped, so that the withdrawal can be processed for those items and excluded for items that are already shipped. An order is considered not shipped if all items have their shipped quantity equal to zero.
     * @param array $post
     * @param array $shippedItems
     * @return array
     */
    private function getRemainingItemToWithdraw(array $items, array $shippedItems): array
    {
        $matchingIds = array_column($shippedItems, 'order_item_id');

        $result = array_filter($items, function($item) use ($matchingIds) {
            return in_array($item['order_item_id'], $matchingIds);
        });

        // Re-index if needed
        $result = array_values($result);
        return $result;
    }
}
