<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Block;

use Magento\Framework\DataObject;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Sales\Block\Order\Totals as TotalsBlock;

/**
 * Adds the loyalty points line to order / invoice / credit memo totals (storefront, admin, e-mails).
 */
class Totals extends AbstractBlock
{
    /**
     * Add the loyalty points line before the grand total when the document has one.
     */
    public function initTotals(): self
    {
        $parent = $this->getParentBlock();
        if (!$parent instanceof TotalsBlock) {
            return $this;
        }
        $source = $parent->getSource();
        if ((float) $source->getData('loyalty_amount') > 0) {
            $parent->addTotalBefore(new DataObject([
                'code' => 'loyalty',
                'label' => __('Loyalty Points'),
                'value' => -$source->getData('loyalty_amount'),
                'base_value' => -$source->getData('base_loyalty_amount'),
            ]), 'grand_total');
        }
        return $this;
    }
}
