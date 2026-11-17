<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Observer;

use MageOS\Loyalty\Model\Points;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Paid invoice: points earned on its grand total (so not on the part paid with points).
 * Credit memo: the same points taken back.
 */
class Earn implements ObserverInterface
{
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
        $invoice = $observer->getData('invoice');
        $document = $invoice ?? $observer->getData('creditmemo');
        $order = $document->getOrder();
        $rate = (float) $this->config->getValue('mageos_loyalty/general/points_per_unit');
        $points = (int) floor((float) $document->getBaseGrandTotal() * $rate);
        if ($points > 0 && $order->getCustomerId()) {
            $this->points->add(
                (int) $order->getCustomerId(),
                $invoice ? $points : -$points,
                (string) ($invoice
                    ? __('Order #%1', $order->getIncrementId())
                    : __('Refund of order #%1', $order->getIncrementId()))
            );
        }
    }
}
