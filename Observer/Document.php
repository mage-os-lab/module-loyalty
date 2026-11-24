<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Observer;

use MageOS\Loyalty\Model\Points;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;

/**
 * Paid invoice: points earned on its grand total (so not on the part paid with points).
 * Credit memo: the points the refunded money earned are taken back, and the points it was paid with given back.
 *
 * Core pays an invoice and refunds a credit memo before saving them. Those events only mark the document; the
 * ledger is written once it is saved, in the same transaction, under its ID: a document that is not saved
 * moves no points, and one saved again moves them once.
 */
class Document implements ObserverInterface
{
    private const PENDING = 'mageos_loyalty_pending';

    /**
     * @param Points $points
     * @param ScopeConfigInterface $config
     */
    public function __construct(
        private readonly Points $points,
        private readonly ScopeConfigInterface $config
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(Observer $observer): void
    {
        $document = $observer->getData('invoice') ?? $observer->getData('creditmemo');
        $event = $observer->getData('event');
        $name = $event instanceof Event ? (string) $event->getName() : '';
        if (!str_ends_with($name, '_save_after')) {
            $document->setData(self::PENDING, true);
            return;
        }
        $order = $document->getOrder();
        $customerId = (int) $order->getCustomerId();
        if (!$document->getData(self::PENDING) || !$customerId) {
            return;
        }
        $entry = ['order_number' => (string) $order->getIncrementId(), 'document_id' => (int) $document->getId()];
        if ($document instanceof Invoice) {
            $rate = (float) $this->config->getValue('mageos_loyalty/general/points_per_unit');
            $earned = (int) floor((float) $document->getBaseGrandTotal() * $rate);
            if ($earned > 0) {
                $this->points->add($customerId, $earned, Points::EARN, $entry);
            }
            return;
        }
        $sums = $this->points->getOrderPoints($customerId, $entry['order_number']);
        $reclaimed = $this->reclaimed($document, $order, $sums);
        if ($reclaimed > 0) {
            $this->points->add($customerId, -$reclaimed, Points::RECLAIM, $entry);
        }
        $returned = $this->returned($document, $order, $sums);
        if ($returned > 0) {
            $this->points->add($customerId, $returned, Points::REFUND, $entry);
        }
    }

    /**
     * Earned points to take back.
     *
     * The refunded share of what the order's invoices earned, at the rate of the day they were paid, and never
     * more than the order still holds; all of them once the order is fully refunded. None for an order the
     * ledger knows no earned points of.
     *
     * @param Creditmemo $creditmemo
     * @param Order $order
     * @param array<string,int> $sums
     */
    private function reclaimed(Creditmemo $creditmemo, Order $order, array $sums): int
    {
        if (!isset($sums[Points::EARN])) {
            return 0;
        }
        $left = $sums[Points::EARN] + ($sums[Points::RECLAIM] ?? 0);
        $invoiced = (float) $order->getBaseTotalInvoiced();
        // the order's refunded total already counts this credit memo
        if ($invoiced <= 0 || (float) $order->getBaseTotalRefunded() >= $invoiced - 0.0001) {
            return max(0, $left);
        }
        $share = (int) round($sums[Points::EARN] * (float) $creditmemo->getBaseGrandTotal() / $invoiced);
        return max(0, min($left, $share));
    }

    /**
     * Spent points to give back.
     *
     * Prorated on the amount, so a later change of the conversion rate does not matter, and never more than
     * the ledger says the order spent and has not got back.
     *
     * @param Creditmemo $creditmemo
     * @param Order $order
     * @param array<string,int> $sums
     */
    private function returned(Creditmemo $creditmemo, Order $order, array $sums): int
    {
        $orderAmount = (float) $order->getData('base_loyalty_amount');
        if ($orderAmount <= 0) {
            return 0;
        }
        $amount = (float) $creditmemo->getData('base_loyalty_amount');
        $points = (int) round((int) $order->getData('loyalty_points') * $amount / $orderAmount);
        return isset($sums[Points::SPEND])
            ? min($points, -array_sum(array_intersect_key($sums, Restore::SPENT)))
            : $points;
    }
}
