<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Observer;

use MageOS\Loyalty\Model\Points;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Spends the points right before the order is placed, and copies the amounts to the order.
 */
class Redeem implements ObserverInterface
{
    /**
     * @param Points $points
     */
    public function __construct(private readonly Points $points)
    {
    }

    /**
     * Amounts are copied to the order only after a successful debit, because Restore relies on them.
     *
     * Shortcut: one order per quote; multi-address checkout would debit once per order.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $quote = $observer->getData('quote');
        $order = $observer->getData('order');
        $points = (int) $quote->getData('loyalty_points');
        if ($points <= 0 || (float) $quote->getData('base_loyalty_amount') <= 0) {
            return;
        }
        $comment = (string) __('Spent on order #%1', $order->getIncrementId());
        if (!$this->points->spend((int) $quote->getCustomerId(), $points, $comment)) {
            throw new LocalizedException(__('Your loyalty points balance has changed. Please review your cart.'));
        }
        $order->setData('loyalty_points', $points);
        $order->setData('base_loyalty_amount', $quote->getData('base_loyalty_amount'));
        $order->setData('loyalty_amount', $quote->getData('loyalty_amount'));
    }
}
