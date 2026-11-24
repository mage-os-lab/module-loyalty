<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Plugin;

use Magento\Quote\Api\Data\TotalSegmentInterface;
use Magento\Quote\Model\Cart\TotalsConverter;

/**
 * Lists the loyalty points before the grand total they are deducted from.
 *
 * The loyalty total is collected after the grand total, so it comes after it in the total segments; checkouts
 * that show the segments in that order (Loki Checkout) would end with the loyalty line.
 */
class GrandTotalLast
{
    /**
     * Move the grand total to the end when points are used.
     *
     * @param TotalsConverter $subject
     * @param array<string,TotalSegmentInterface> $segments by total code
     * @return array<string, TotalSegmentInterface>
     */
    public function afterProcess(TotalsConverter $subject, array $segments): array
    {
        if (isset($segments['loyalty'], $segments['grand_total'])) {
            $grandTotal = $segments['grand_total'];
            unset($segments['grand_total']);
            $segments['grand_total'] = $grandTotal;
        }
        return $segments;
    }
}
