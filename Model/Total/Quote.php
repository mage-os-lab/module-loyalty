<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Model\Total;

use MageOS\Loyalty\Model\Points;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote as QuoteModel;
use Magento\Quote\Model\Quote\Address\Total;
use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;

/**
 * Converts the points the customer chose to spend ("loyalty_points" on the quote) into money,
 * at the rate configured in the admin, capped by the points balance and the grand total.
 */
class Quote extends AbstractTotal
{
    /**
     * @param Points $points
     * @param PriceCurrencyInterface $priceCurrency
     */
    public function __construct(
        private readonly Points $points,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
        $this->setCode('loyalty');
    }

    /**
     * Deduct the amount from the grand total and keep only the points the total can absorb.
     *
     * The address that carries no items (billing, or shipping of a virtual cart) is skipped.
     *
     * Multi-address checkout places one order per address without the quote submit event Redeem notes the
     * points on: nothing is deducted there, or the orders would be reduced with no debit.
     *
     * The amount is rounded down to cents, so the points kept never exceed those asked for, even when one
     * point is worth less than a cent.
     *
     * @param QuoteModel $quote
     * @param ShippingAssignmentInterface $shippingAssignment
     * @param Total $total
     */
    public function collect(QuoteModel $quote, ShippingAssignmentInterface $shippingAssignment, Total $total)
    {
        if ($quote->getData('is_multi_shipping')) {
            $quote->setData('base_loyalty_amount', 0.0)->setData('loyalty_amount', 0.0);
            return $this;
        }
        if (!$shippingAssignment->getItems()) {
            return $this;
        }
        $points = (int) $quote->getData('loyalty_points');
        $value = $this->points->getPointValue();
        $baseAmount = 0.0;
        if ($points > 0 && $value > 0 && $quote->getData('customer_id')) {
            // a refund of points already spent leaves a negative balance: it pays nothing
            $points = max(0, min($points, $this->points->getBalance((int) $quote->getData('customer_id'))));
            $baseAmount = min(
                floor(round($points * $value * 100, 4)) / 100,
                max(0.0, (float) $total->getBaseGrandTotal())
            );
            $points = (int) ceil(round($baseAmount / $value, 4));
        }
        $amount = min(
            $this->priceCurrency->convertAndRound($baseAmount, $quote->getStore()),
            (float) $total->getGrandTotal()
        );

        $total->setBaseGrandTotal($total->getBaseGrandTotal() - $baseAmount);
        $total->setGrandTotal($total->getGrandTotal() - $amount);
        $quote->setData('loyalty_points', $baseAmount > 0 ? $points : null);
        $quote->setData('base_loyalty_amount', $baseAmount)->setData('loyalty_amount', $amount);
        return $this;
    }

    /**
     * Total segment shown in the cart and checkout summary.
     *
     * @param QuoteModel $quote
     * @param Total $total
     * @return array<string, mixed>
     */
    public function fetch(QuoteModel $quote, Total $total)
    {
        $amount = (float) $quote->getData('loyalty_amount');
        return $amount > 0
            ? [
                'code' => $this->getCode(),
                'title' => __('Loyalty Points (%1)', (int) $quote->getData('loyalty_points')),
                'value' => -$amount,
            ]
            : [];
    }
}
