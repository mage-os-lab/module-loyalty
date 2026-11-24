<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Model\ResourceModel\Grid;

use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;

/**
 * Admin grid collection of the points ledger, with each customer's e-mail. It also feeds the grid export.
 */
class Collection extends SearchResult
{
    /**
     * Join the customer as "customer_email"; columns that also exist there are filtered on the ledger.
     */
    protected function _initSelect()
    {
        parent::_initSelect();
        $this->getSelect()->joinLeft(
            ['c' => $this->getTable('customer_entity')],
            'c.entity_id = main_table.customer_id',
            ['customer_email' => 'email']
        );
        $this->addFilterToMap('created_at', 'main_table.created_at');
        $this->addFilterToMap('customer_email', 'c.email');
    }
}
