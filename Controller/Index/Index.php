<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Controller\Index;

use Magento\Customer\Controller\AccountInterface;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 * AccountInterface: core's customer_account plugin redirects guests to the login page.
 */
class Index implements AccountInterface, HttpGetActionInterface
{
    /**
     * @param PageFactory $pageFactory
     */
    public function __construct(private readonly PageFactory $pageFactory)
    {
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set((string) __('Loyalty Points'));
        return $page;
    }
}
