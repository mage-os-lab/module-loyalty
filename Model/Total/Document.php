<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Model\Total;

use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Total\AbstractTotal;

/**
 * Invoice and credit memo total: the order's loyalty points amount is consumed by the first invoices
 * and given back by the first credit memos, up to each document's grand total.
 */
class Document extends AbstractTotal
{
    /**
     * Take this document's share of the order amount, net of the other non-canceled documents.
     *
     * @param Invoice|Creditmemo $document
     */
    public function collect($document): self
    {
        $order = $document->getOrder();
        $siblings = $document instanceof Creditmemo
            ? $order->getCreditmemosCollection()
            : $order->getInvoiceCollection();

        $fields = ['base_loyalty_amount' => 'base_grand_total', 'loyalty_amount' => 'grand_total'];
        foreach ($fields as $field => $grandTotal) {
            $left = (float) $order->getData($field);
            foreach ($siblings ?: [] as $sibling) {
                $counts = $sibling->getId() && $sibling !== $document;
                if ($counts && (int) $sibling->getState() !== $document::STATE_CANCELED) {
                    $left -= (float) $sibling->getData($field);
                }
            }
            $used = min(max($left, 0.0), (float) $document->getData($grandTotal));
            $document->setData($field, $used);
            $document->setData($grandTotal, $document->getData($grandTotal) - $used);
        }
        return $this;
    }
}
