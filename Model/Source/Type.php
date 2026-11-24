<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Model\Source;

use MageOS\Loyalty\Model\Points;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Types of the points ledger entries.
 */
class Type implements OptionSourceInterface
{
    /**
     * @inheritdoc
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray()
    {
        return [
            ['value' => Points::EARN, 'label' => __('Earned on an invoice')],
            ['value' => Points::RECLAIM, 'label' => __('Taken back on a refund')],
            ['value' => Points::SPEND, 'label' => __('Spent on an order')],
            ['value' => Points::RETURN, 'label' => __('Returned: order canceled')],
            ['value' => Points::REFUND, 'label' => __('Returned: order refunded')],
            ['value' => Points::ADJUST, 'label' => __('Adjustment')],
        ];
    }
}
