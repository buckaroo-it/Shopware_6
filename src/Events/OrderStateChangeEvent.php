<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Events;

use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Service\InvoiceService;
use Buckaroo\Shopware6\Service\SettingsService;
use Buckaroo\Shopware6\Service\TransactionService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;

class OrderStateChangeEvent implements EventSubscriberInterface
{
    protected TransactionService $transactionService;

    protected InvoiceService $invoiceService;

    protected SettingsService $settingsService;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * OrderDeliveryStateChangeEventTest constructor.
     */
    public function __construct(
        TransactionService $transactionService,
        InvoiceService $invoiceService,
        SettingsService $settingsService,
        LoggerInterface $logger
    ) {
        $this->transactionService      = $transactionService;
        $this->invoiceService          = $invoiceService;
        $this->settingsService         = $settingsService;
        $this->logger                  = $logger;
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'state_enter.order_delivery.state.shipped' => 'onOrderDeliveryStateShipped',
        ];
    }

    public function onOrderDeliveryStateShipped(OrderStateMachineStateChangeEvent $event)
    {
        $context = $event->getContext();
        $eventOrder = $event->getOrder();
        $order = $this->checkoutHelper->getOrderById($eventOrder->getId(), $context);
        $customFields = $this->transactionService->getCustomFields($order, $context);
        $salesChannelId =  $event->getSalesChannelId();

        if(
            isset($customFields['brqPaymentMethod']) &&
            $customFields['brqPaymentMethod'] == 'Billink' &&
            $this->checkoutHelper->getSettingsValue('BillinkMode', $salesChannelId) == 'authorize' &&
            $this->checkoutHelper->getSettingsValue('BillinkCreateInvoiceAfterShipment', $salesChannelId)
        ) {
            $brqInvoicenumber = $customFields['brqInvoicenumber'] ?? $order->getOrderNumber();
            $this->invoiceService->generateInvoice($eventOrder, $context, $brqInvoicenumber,  $salesChannelId);
        }

        return true;
    }

}
