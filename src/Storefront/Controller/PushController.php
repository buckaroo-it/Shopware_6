<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Buckaroo\Shopware6\Events\PushProcessingEvent;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\Request;
use Buckaroo\Shopware6\Helpers\CheckoutHelper;
use Buckaroo\Shopware6\Service\InvoiceService;
use Symfony\Component\Routing\Annotation\Route;
use Buckaroo\Shopware6\Service\TransactionService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Buckaroo\Shopware6\Service\StateTransitionService;
use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;
use Shopware\Storefront\Controller\StorefrontController;
use Buckaroo\Shopware6\Service\SignatureValidationService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\System\StateMachine\Exception\StateMachineNotFoundException;
use Shopware\Core\System\StateMachine\Exception\StateMachineStateNotFoundException;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;

class PushController extends StorefrontController
{
    private LoggerInterface $logger;

    private CheckoutHelper $checkoutHelper;

    protected SignatureValidationService $signatureValidationService;

    protected TransactionService $transactionService;

    protected StateTransitionService $stateTransitionService;

    protected InvoiceService $invoiceService;

    protected EventDispatcherInterface $eventDispatcher;

    public function __construct(
        SignatureValidationService $signatureValidationService,
        TransactionService $transactionService,
        StateTransitionService $stateTransitionService,
        InvoiceService $invoiceService,
        CheckoutHelper $checkoutHelper,
        LoggerInterface $logger,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->signatureValidationService = $signatureValidationService;
        $this->transactionService         = $transactionService;
        $this->stateTransitionService     = $stateTransitionService;
        $this->invoiceService        = $invoiceService;
        $this->checkoutHelper        = $checkoutHelper;
        $this->logger                = $logger;
        $this->eventDispatcher       = $eventDispatcher;
    }

    /**
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return JsonResponse
     */
    #[Route(path: "/buckaroo/push", defaults: ['_routeScope' => ['storefront']], options: ["seo" => false], name: "buckaroo.payment.push", methods:["POST"])]
    public function pushBuckaroo(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {   

        $event = new PushProcessingEvent(
            $request,
            $salesChannelContext,
        );
        
        $this->logger->info(__METHOD__ . "|1|", [$_POST]);

        $status             = (string)$request->request->get('brq_statuscode');
        $context            = $salesChannelContext->getContext();
        $brqAmount          = (float)$request->request->get('brq_amount');
        $brqOrderId         = (string)$request->request->get('ADD_orderId');
        if ($request->request->has('brq_AdditionalParameters_orderId')) {
            $brqOrderId = (string)$request->request->get('brq_AdditionalParameters_orderId');
        }

        $brqAmountCredit    = (float)$request->request->get('brq_amount_credit', 0);
        $brqInvoicenumber   = (string)$request->request->get('brq_invoicenumber');
        $orderTransactionId = (string)$request->request->get('ADD_orderTransactionId');
        if ($request->request->has('brq_AdditionalParameters_orderTransactionId')) {
            $orderTransactionId = (string)$request->request->get('brq_AdditionalParameters_orderTransactionId');
        }

        $brqTransactionType = (string)$request->request->get('brq_transaction_type');
        $paymentMethod      = (string)$request->request->get('brq_primary_service');
        $mutationType       = (string)$request->request->get('brq_mutationtype');
        $brqPaymentMethod   = (string)$request->request->get('brq_transaction_method');
        $originalTransactionKey   = (string)$request->request->get('brq_transactions');
        $salesChannelId     =  $salesChannelContext->getSalesChannelId();

        if (empty($brqOrderId) || empty($orderTransactionId)) {
            return $this->response($event, 'buckaroo.messages.paymentError', false);
        }

        if (!$this->signatureValidationService->validateSignature(
            $request,
            $salesChannelId
        )
        ) {
            $this->logger->info(__METHOD__ . "|5|");
            return $this->response($event, 'buckaroo.messages.signatureIncorrect', false);
        }

        //skip mutationType Informational
        if ($mutationType == ResponseStatus::BUCKAROO_MUTATION_TYPE_INFORMATIONAL) {
            $this->logger->info(__METHOD__ . "|5.1|");
            $data = [
                'originalTransactionKey' => $originalTransactionKey,
                'brqPaymentMethod'       => $brqPaymentMethod,
                'brqInvoicenumber'       => $brqInvoicenumber,
            ];
            $this->transactionService->saveTransactionData($orderTransactionId, $context, $data);

            return $this->response($event, 'buckaroo.messages.skipInformational');
        }

        if (!empty($request->request->get('brq_transaction_method'))
            && ($request->request->get('brq_transaction_method') === 'paypal')
            && ($status == ResponseStatus::BUCKAROO_STATUSCODE_PENDING_PROCESSING)
        ) {
            $status = ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_USER;
        }

        $order = $this->checkoutHelper->getOrderById($brqOrderId, $context);

        if ($order === null) {
            return $this->response($event, 'buckaroo.messages.paymentError', false);
        }

        if (!$this->checkDuplicatePush($order, $orderTransactionId, $context)) {
            return $this->response($event, 'buckaroo.messages.pushAlreadySend', false);
        }

        if ($brqTransactionType != ResponseStatus::BUCKAROO_AUTHORIZE_TYPE_GROUP_TRANSACTION) {
            $this->logger->info(__METHOD__ . "|10|");
            $this->checkoutHelper->saveBuckarooTransaction($request);
        }

        $totalPrice = $order->getPrice()->getTotalPrice();

        //Check if the push is a refund request or cancel authorize
        if ($brqAmountCredit > 0) {
            $this->logger->info(__METHOD__ . "|15|", [$brqAmountCredit]);
            if ($status != ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS &&
                $brqTransactionType == ResponseStatus::BUCKAROO_AUTHORIZE_TYPE_CANCEL
            ) {
                $this->logger->info(__METHOD__ . "|20|");
                return $this->response($event, 'buckaroo.messages.paymentCancelled');
            }

            $alreadyRefunded = 0;
            if ($orderTransaction = $this->transactionService->getOrderTransactionById(
                $context,
                $orderTransactionId
            )
            ) {
                $this->logger->info(__METHOD__ . "|21|");
                $customFields = $orderTransaction->getCustomFields() ?? [];
                if (!empty($customFields['alreadyRefunded'])) {
                    $this->logger->info(__METHOD__ . "|22|");
                    $alreadyRefunded = $customFields['alreadyRefunded'];
                }
            }

            $this->logger->info(
                __METHOD__ . "|23|",
                [
                    $brqAmountCredit,
                $alreadyRefunded,
                $brqAmountCredit + $alreadyRefunded,
                $totalPrice
                ]
            );

            $status = $this->checkoutHelper->areEqualAmounts($brqAmountCredit + $alreadyRefunded, $totalPrice)
                ? 'refunded'
                : 'partial_refunded';

            $this->logger->info(__METHOD__ . "|25|", [$status]);
            $this->transactionService->saveTransactionData(
                $orderTransactionId,
                $context,
                [$status => 1, 'alreadyRefunded' => $brqAmountCredit + $alreadyRefunded]
            );

            $this->stateTransitionService
                ->transitionPaymentState(
                    $status,
                    $orderTransactionId,
                    $context
                );

            return $this->response($event, 'buckaroo.messages.refundSuccessful');
        }

        if ($status == ResponseStatus::BUCKAROO_STATUSCODE_SUCCESS) {
            $this->logger->info(__METHOD__ . "|30|");
            try {
                if ($this->stateTransitionService->isOrderState($order, ['cancel'])) {
                    $this->logger->info(__METHOD__ . "|35|");
                    $this->stateTransitionService->changeOrderStatus($order, $context, 'reopen');
                }

                if ($this->stateTransitionService->isTransitionPaymentState(
                    ['refunded', 'partial_refunded'],
                    $orderTransactionId,
                    $context
                )
                ) {
                    $this->logger->info(__METHOD__ . "|40|");
                    return $this->response($event, 'buckaroo.messages.paymentUpdatedEarlier');
                }

                $customFields = $this->transactionService->getCustomFields($order, $context);
                $paymentSuccesStatus = $this->getPaymentSuccessStatus($salesChannelId);
                $alreadyPaid = round($brqAmount + ($customFields['alreadyPaid'] ?? 0), 2);
                $paymentState        = ($alreadyPaid >= round($totalPrice, 2)) ? $paymentSuccesStatus : "pay_partially";
                $data                = [];
                if ($paymentMethod && (strtolower($paymentMethod) == 'klarnakp')) {
                    $this->logger->info(__METHOD__ . "|42|");
                    $paymentState              = 'do_pay';
                    $data['reservationNumber'] = $request->request->get('brq_SERVICE_klarnakp_ReservationNumber');
                }
                $this->logger->info(__METHOD__ . "|45|", [$paymentState, $brqAmount, $totalPrice]);

                $this->stateTransitionService->transitionPaymentState(
                    $paymentState,
                    $orderTransactionId,
                    $context
                );

                $paymentMethodCode = $paymentMethod ? $paymentMethod : $request->request->get('brq_transaction_method');
                $data = array_merge($data, [
                    'originalTransactionKey' => $request->request->get('brq_transactions'),
                    'brqPaymentMethod'       => $paymentMethodCode,
                    'alreadyPaid' => $alreadyPaid,
                ]);

                $this->transactionService->saveTransactionData(
                    $orderTransactionId,
                    $context,
                    $data
                );

                $orderStatus = $this->checkoutHelper->getSettingsValue('orderStatus', $salesChannelId);
                if (is_string($orderStatus)) {
                    if ($orderStatus == 'complete') {
                        $orderStatus = 'process';
                    }
                    $this->stateTransitionService->changeOrderStatus($order, $context, $orderStatus);
                }

                $this->logger->info(__METHOD__ . "|50.1|");
                if (!$this->invoiceService->isInvoiced($brqOrderId, $context) &&
                    !$this->invoiceService->isCreateInvoiceAfterShipment(
                        $brqTransactionType,
                        false,
                        $salesChannelId
                    )
                ) {
                    $this->logger->info(__METHOD__ . "|50.2|");
                    if (round($brqAmount, 2) == round($totalPrice, 2)) {
                        $this->invoiceService->generateInvoice(
                            $order,
                            $context,
                            $salesChannelId
                        );
                    }
                }
            } catch (InconsistentCriteriaIdsException | IllegalTransitionException | StateMachineNotFoundException
                 | StateMachineStateNotFoundException $exception
            ) {
                $this->logger->info(__METHOD__ . "|55|");
                throw new AsyncPaymentFinalizeException($orderTransactionId, $exception->getMessage());
            }
            $this->logger->info(__METHOD__ . "|60|");
            return $this->response($event, 'buckaroo.messages.paymentUpdated');
        }

        if (in_array(
            $status,
            [
                    ResponseStatus::BUCKAROO_STATUSCODE_TECHNICAL_ERROR,
                    ResponseStatus::BUCKAROO_STATUSCODE_VALIDATION_FAILURE,
                    ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_MERCHANT,
                    ResponseStatus::BUCKAROO_STATUSCODE_FAILED,
                    ResponseStatus::BUCKAROO_STATUSCODE_REJECTED
                ]
        )
        ) {
            if ($this->stateTransitionService->isTransitionPaymentState(
                ['paid','pay_partially'],
                $orderTransactionId,
                $context
            )
            ) {
                return $this->response($event, 'buckaroo.messages.skippedPush');
            }
            $this->stateTransitionService->transitionPaymentState("fail", $orderTransactionId, $context);

            return $this->response($event, 'buckaroo.messages.orderCancelled');
        }

        if ($status == ResponseStatus::BUCKAROO_STATUSCODE_CANCELLED_BY_USER) {
            if ($this->stateTransitionService->isTransitionPaymentState(
                ['paid','pay_partially'],
                $orderTransactionId,
                $context
            )
            ) {
                return $this->response($event, 'buckaroo.messages.skippedPush');
            }
            $this->stateTransitionService->transitionPaymentState("cancelled", $orderTransactionId, $context);

            return $this->response($event, 'buckaroo.messages.orderCancelled');
        }

        return $this->response($event, 'buckaroo.messages.paymentError', false);
    }

    private function getPaymentSuccessStatus(string $salesChannelId): string
    {
        $status = $this->checkoutHelper->getSettingsValue('paymentSuccesStatus', $salesChannelId);
        if ($status !== null && is_string($status)) {
            return $status;
        }
        return "completed";
    }

    private function response(
        PushProcessingEvent $event,
        string $message,
        bool $status = true
    ): JsonResponse
    {
        $this->eventDispatcher->dispatch($event);
        return $this->json(['status' => $status, 'message' => $this->trans($message)]);
    }

    private function checkDuplicatePush(
        OrderEntity $order,
        string $orderTransactionId,
        Context $context
    ): bool {
        $rand = range(0, 6, 2);
        shuffle($rand);
        usleep(array_shift($rand) * 1000000);
        $postData = $_POST;
        $calculated = $this->signatureValidationService->calculatePushHash($postData);
        $this->logger->info(__METHOD__ . "|calculated|" . $calculated);

        $customFields = $this->transactionService->getCustomFields($order, $context);
        $pushHash = isset($customFields['pushHash']) ? $customFields['pushHash'] : '';

        $this->logger->info(__METHOD__ . "|pushHash|" . $pushHash);
        $customFields['pushHash'] = $calculated;
        $this->transactionService->updateTransactionCustomFields($orderTransactionId, $customFields);
        if ($pushHash == $calculated) {
            $this->logger->info(__METHOD__ . "|pushHash == calculated|");
            return false;
        }

        return true;
    }
}
