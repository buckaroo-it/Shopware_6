<?php
/*
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License
 * It is available through the world-wide-web at this URL:
 * https://tldrlegal.com/license/mit-license
 * If you are unable to obtain it through the world-wide-web, please send an email
 * to support@buckaroo.nl so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this module to newer
 * versions in the future. If you wish to customize this module for your
 * needs please contact support@buckaroo.nl for more information.
 *
 * @copyright Copyright (c) Buckaroo B.V.
 * @license   https://tldrlegal.com/license/mit-license
 */

namespace Buckaroo\PaymentMethods\Billink\Models;

use Buckaroo\Models\ServiceParameter;
use Buckaroo\PaymentMethods\Billink\Service\ParameterKeys\ArticleAdapter;
use Buckaroo\PaymentMethods\Traits\CountableGroupKey;

class Pay extends ServiceParameter
{
    use CountableGroupKey;

    /**
     * @var array|string[]
     */
    private array $countableProperties = ['articles'];

    /**
     * @var Recipient
     */
    protected Recipient $billingRecipient;
    /**
     * @var Recipient
     */
    protected Recipient $shippingRecipient;

    /**
     * @var string
     */
    protected string $trackandtrace;
    /**
     * @var string
     */
    protected string $vATNumber;

    /**
     * @var array
     */
    protected array $articles = [];

    /**
     * @var array|\string[][]
     */
    protected array $groupData = [
        'articles'   => [
            'groupType' => 'Article'
        ]
    ];

    /**
     * @param $billing
     * @return Recipient
     */
    public function billing($billing = null)
    {
        if(is_array($billing))
        {
            $this->billingRecipient = new Recipient('Billing', $billing);
            $this->shippingRecipient = new Recipient('Billing', $billing);
        }

        return $this->billingRecipient;
    }

    /**
     * @param $shipping
     * @return Recipient
     */
    public function shipping($shipping = null)
    {
        if(is_array($shipping))
        {
            $this->shippingRecipient = new Recipient('Shipping', $shipping);
        }

        return $this->shippingRecipient;
    }

    /**
     * @param array|null $articles
     * @return array
     */
    public function articles(?array $articles = null)
    {
        if(is_array($articles))
        {
            foreach($articles as $article)
            {
                $this->articles[] = new ArticleAdapter(new Article($article));
            }
        }

        return $this->articles;
    }
}