<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Controller\Adminhtml\Points;

use Magento\Backend\App\Action;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;

/**
 * Admin grid of the points ledger, with the form that adjusts a balance.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Loyalty::points';

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        if ($page instanceof Page) {
            $page->setActiveMenu('MageOS_Loyalty::points');
            $page->getConfig()->getTitle()->prepend((string) __('Loyalty Points'));
        }
        return $page;
    }
}
