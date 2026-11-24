<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Observer;

use MageOS\Loyalty\Model\Points;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;

/**
 * Gives spent points back when the order is not placed, or is canceled (the part not invoiced).
 *
 * "order_cancel_after" does not fire when a payment in review is denied (core cancels the order without it),
 * so an order saved as newly canceled is handled too. The ledger holds one such entry per order: a second pass
 * writes nothing.
 */
class Restore implements ObserverInterface
{
    /**
     * Entry types that make what an order has spent and not got back (as a negative sum).
     */
    public const SPENT = [Points::SPEND => 1, Points::RETURN => 1, Points::REFUND => 1];

    /**
     * @param Points $points
     * @param OrderResource $orderResource
     */
    public function __construct(
        private readonly Points $points,
        private readonly OrderResource $orderResource
    ) {
    }

    /**
     * Never more than the ledger says the order spent and has not got back.
     *
     * An order the ledger does not know (placed before 1.1.0) gets its prorated points on a cancellation.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getData('order');
        $event = $observer->getData('event');
        if ($event instanceof Event && $event->getName() === 'sales_order_save_before'
            && ($order->getState() !== Order::STATE_CANCELED || $order->getOrigData('state') === Order::STATE_CANCELED)
        ) {
            return;
        }
        $customerId = (int) $order->getCustomerId();
        $number = (string) $order->getIncrementId();
        if (!$customerId || $number === '') {
            return;
        }
        if ($observer->getData('quote')) {
            // core also reports a failure that comes after the order was saved: the order stays, paid with points
            if (!$order->getId() || !$this->isSaved($order)) {
                $this->points->unspend($customerId, $number);
            }
            return;
        }
        $orderAmount = (float) $order->getData('base_loyalty_amount');
        if ($orderAmount <= 0) {
            return;
        }
        $amount = $orderAmount;
        foreach ($order->getInvoiceCollection() as $invoice) {
            $amount -= $invoice->isCanceled() ? 0 : (float) $invoice->getData('base_loyalty_amount');
        }
        $points = (int) round((int) $order->getData('loyalty_points') * $amount / $orderAmount);
        $sums = $this->points->getOrderPoints($customerId, $number);
        if (isset($sums[Points::SPEND])) {
            $points = min($points, -array_sum(array_intersect_key($sums, self::SPENT)));
        }
        if ($points > 0) {
            $this->points->add($customerId, $points, Points::RETURN, ['order_number' => $number]);
        }
    }

    /**
     * Whether the order is really in the database: a save that failed can leave an ID on the object.
     *
     * @param Order $order
     */
    private function isSaved(Order $order): bool
    {
        $connection = $this->orderResource->getConnection();
        return $connection && $connection->fetchOne(
            $connection->select()
                ->from($this->orderResource->getMainTable(), 'entity_id')
                ->where('entity_id = ?', $order->getId())
        );
    }
}
