<?php declare(strict_types=1);


namespace Buckaroo\Shopware6\Events;

use Buckaroo\Shopware6\Helpers\Helper;
use Buckaroo\Shopware6\BuckarooPayment;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryStates;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;

use Buckaroo\Shopware6\Buckaroo\Payload\TransactionRequest;
use Buckaroo\Shopware6\Helpers\UrlHelper;

use Buckaroo\Shopware6\Helpers\CheckoutHelper;

use Psr\Log\LoggerInterface;

class OrderStateChangeEvent implements EventSubscriberInterface
{
    /** @var EntityRepositoryInterface */
    private $orderRepository;
    /** @var EntityRepositoryInterface */
    private $orderDeliveryRepository;
    /** @var helper */
    private $helper;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * OrderDeliveryStateChangeEventTest constructor.
     * @param EntityRepositoryInterface $orderRepository
     * @param EntityRepositoryInterface $orderDeliveryRepository
     * @param helper $helper
     */
    public function __construct(
        EntityRepositoryInterface $orderRepository,
        EntityRepositoryInterface $orderDeliveryRepository,
        Helper $helper,
        CheckoutHelper $checkoutHelper,
        LoggerInterface $logger
    ) {
        $this->orderRepository = $orderRepository;
        $this->orderDeliveryRepository = $orderDeliveryRepository;
        $this->helper = $helper;
        $this->checkoutHelper = $checkoutHelper;
        $this->logger = $logger;
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'state_enter.order_transaction.state.refunded' => 'onOrderTransactionRefunded',
            'state_enter.order_transaction.state.refunded_partially' => 'onOrderTransactionRefundedPartially',
        ];
    }

    public function sendTransactionRefund(OrderStateMachineStateChangeEvent $event, $state)
    {
        $order = $this->getOrder($event);
        $customFields = $this->getCustomFields($order, $event);

        if (!$this->isBuckarooPaymentMethod($order)) {
            return;
        }

        if($customFields['refund']==0){
            return false;
        }

        if($customFields['refunded']==1){
            return false;
        }

        $request = new TransactionRequest;
        $request->setServiceAction('Refund');
        $request->setDescription('Refund for order #' . $order->getOrderNumber());
        $request->setServiceName($customFields['brqPaymentMethod']);
        $request->setAmountCredit($order->getAmountTotal());
        $request->setInvoice($order->getOrderNumber());
        $request->setOrder($order->getOrderNumber());
        $request->setCurrency('EUR');
        $request->setOriginalTransactionKey($customFields['originalTransactionKey']);

        $url = $this->checkoutHelper->getTransactionUrl($customFields['serviceName']);
        $bkrClient = $this->helper->initializeBkr();
        return $bkrClient->post($url, $request, 'Buckaroo\Shopware6\Buckaroo\Payload\TransactionResponse');
    }

    public function onOrderTransactionRefunded(OrderStateMachineStateChangeEvent $event)
    {
        return $this->sendTransactionRefund($event, 'refund');
    }

    public function onOrderTransactionRefundedPartially(OrderStateMachineStateChangeEvent $event)
    {
        return $this->sendTransactionRefund($event, 'refund_partially');
    }

    /**
     * Check if this event is triggered using a Buckaroo Payment Method
     *
     * @param OrderEntity $order
     * @return bool
     */
    private function isBuckarooPaymentMethod(OrderEntity $order): bool
    {
        $transaction = $order->getTransactions()->first();
        if (!$transaction || !$transaction->getPaymentMethod() || !$transaction->getPaymentMethod()->getPlugin()) {
            return false;
        }

        $plugin = $transaction->getPaymentMethod()->getPlugin();

        return $plugin->getBaseClass() === BuckarooPayment::class;
    }

    /**
     * @param OrderStateMachineStateChangeEvent $event
     * @return OrderEntity
     * @throws InconsistentCriteriaIdsException
     */
    private function getOrder(OrderStateMachineStateChangeEvent $event): OrderEntity
    {
        $order = $event->getOrder();
        $orderId = $order->getId();
        $orderCriteria = new Criteria([$orderId]);
        $orderCriteria->addAssociation('orderCustomer.salutation');
        $orderCriteria->addAssociation('stateMachineState');
        $orderCriteria->addAssociation('transactions');
        $orderCriteria->addAssociation('transactions.paymentMethod');
        $orderCriteria->addAssociation('transactions.paymentMethod.plugin');
        $orderCriteria->addAssociation('salesChannel');
        return $this->orderRepository->search($orderCriteria, $event->getContext())->first();
    }

    private function getCustomFields($order, OrderStateMachineStateChangeEvent $event)
    {
        $transaction = $order->getTransactions()->first();

        $orderTransaction = $this->checkoutHelper->getOrderTransactionById(
            $event->getContext(),
            $transaction->getId()
        );
        $customField = $orderTransaction->getCustomFields() ?? [];

        // $this->logger->error(serialize($transaction));

        $method_path = str_replace('Handlers', 'PaymentMethods', str_replace('PaymentHandler', '', $transaction->getPaymentMethod()->getHandlerIdentifier()));
        $paymentMethod = new $method_path;
        $customField['refund'] = $paymentMethod->canRefund() ? 1 : 0;
        $customField['serviceName'] = $paymentMethod->getBuckarooKey();

        return $customField;
    }

}
