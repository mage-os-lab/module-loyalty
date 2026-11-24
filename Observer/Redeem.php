<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Observer;

use MageOS\Loyalty\Model\Points;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Spends the points when the order is placed, and copies the amounts to the order.
 *
 * "sales_model_service_quote_submit_before" fires before core's try block: an exception thrown later on that
 * event (by another module) would leave the points spent with no order and no failure event. So it only notes
 * the points on the order; they are spent on "sales_order_place_before", inside the try, where a failure fires
 * "sales_model_service_quote_submit_failure" and Restore gives them back.
 */
class Redeem implements ObserverInterface
{
    private const PENDING = 'mageos_loyalty_pending';

    /**
     * @param Points $points
     */
    public function __construct(private readonly Points $points)
    {
    }

    /**
     * Amounts are copied to the order only after a successful debit.
     *
     * Shortcut: one order per quote; multi-address checkout does not use points.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getData('order');
        $quote = $observer->getData('quote');
        if ($quote) {
            if ((int) $quote->getData('loyalty_points') > 0 && (float) $quote->getData('base_loyalty_amount') > 0) {
                $order->setData(self::PENDING, [
                    'customer_id' => (int) $quote->getCustomerId(),
                    'loyalty_points' => (int) $quote->getData('loyalty_points'),
                    'base_loyalty_amount' => $quote->getData('base_loyalty_amount'),
                    'loyalty_amount' => $quote->getData('loyalty_amount'),
                ]);
            }
            return;
        }
        $pending = $order->getData(self::PENDING);
        if (!$pending) {
            return;
        }
        $order->unsetData(self::PENDING);
        $entry = ['order_number' => (string) $order->getIncrementId()];
        if (!$this->points->spend($pending['customer_id'], $pending['loyalty_points'], Points::SPEND, $entry)) {
            throw new LocalizedException(__('Your loyalty points balance has changed. Please review your cart.'));
        }
        unset($pending['customer_id']);
        $order->addData($pending);
    }
}
