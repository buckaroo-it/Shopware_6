<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service\Config\Payment;

use Buckaroo\Shopware6\Service\UrlService;
use Buckaroo\Shopware6\Service\Config\State;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Buckaroo\Shopware6\Service\Config\ConfigInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class Common implements ConfigInterface
{
    protected UrlService $urlService;
    protected SalesChannelRepository $paymentMethodRepository;

    public function __construct(
        UrlService $urlService,
        SalesChannelRepository $paymentMethodRepository
    ) {
        $this->urlService = $urlService;
        $this->paymentMethodRepository = $paymentMethodRepository;
    }

    public function get(State $state): array
    {
        return [
            'payment_labels'           => $this->getPaymentLabels($state),
            'buckarooFee'              => $state->getPaymentFee(),
            'backLink'                 => $this->urlService->getRestoreUrl(),
        ];
    }

    private function getPaymentLabels(State $state): array
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('active', true))
            ->addAssociation('media');
        $paymentLabels = [];
        /** @var \Shopware\Core\Checkout\Payment\PaymentMethodCollection $paymentMethods */
        $paymentMethods = $this->paymentMethodRepository
            ->search(
                $criteria,
                $state->getSalesChannel()
            )
            ->getEntities();

        /** @var \Shopware\Core\Checkout\Payment\PaymentMethodEntity $paymentMethod */
        foreach ($paymentMethods as $paymentMethod) {
            $buckarooPaymentKey = $state->getBuckarooKeyByPayment($paymentMethod);
            if ($buckarooPaymentKey !== null) {
                $paymentLabels[$buckarooPaymentKey] = $this->getBuckarooFeeLabel($state, $buckarooPaymentKey);
            }
        }

        return $paymentLabels;
    }

    protected function getBuckarooFeeLabel(State $state, string $buckarooKey): string
    {
        $label = $state->getPaymentLabel($buckarooKey);

        if ($buckarooFee = (string)$state->getPaymentFee($buckarooKey)) {
            $label .= ' +' . $state->getSalesChannel()->getCurrency()->getSymbol() . $buckarooFee;
        }
        return $label;
    }
}
