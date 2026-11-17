<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Observer;

use MageOS\Loyalty\Model\Points;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Gives spent points back: failed order placement, canceled order (the part not invoiced), credit memo.
 * Points are prorated on the amount, so a later change of the conversion rate does not matter.
 */
class Restore implements ObserverInterface
{
    /**
     * @param Points $points
     */
    public function __construct(private readonly Points $points)
    {
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer): void
    {
        $creditmemo = $observer->getData('creditmemo');
        $order = $creditmemo ? $creditmemo->getOrder() : $observer->getData('order');
        $orderAmount = (float) $order->getData('base_loyalty_amount');
        if ($orderAmount <= 0 || !$order->getCustomerId()) {
            return;
        }
        if ($creditmemo) {
            $amount = (float) $creditmemo->getData('base_loyalty_amount');
        } else {
            $amount = $orderAmount;
            foreach ($order->getInvoiceCollection() as $invoice) {
                $amount -= $invoice->isCanceled() ? 0 : (float) $invoice->getData('base_loyalty_amount');
            }
        }
        $points = (int) round((int) $order->getData('loyalty_points') * $amount / $orderAmount);
        if ($points > 0) {
            $this->points->add(
                (int) $order->getCustomerId(),
                $points,
                (string) __('Points returned for order #%1', $order->getIncrementId())
            );
        }
    }
}
