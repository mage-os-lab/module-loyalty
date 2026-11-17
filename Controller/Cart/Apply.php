<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Controller\Cart;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;

/**
 * Sets how many loyalty points the cart spends (0 or "remove" = none). The total collector caps the number.
 */
class Apply implements HttpPostActionInterface
{
    /**
     * @param RequestInterface $request
     * @param Session $checkoutSession
     * @param CartRepositoryInterface $quoteRepository
     * @param ManagerInterface $messages
     * @param RedirectFactory $redirectFactory
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly Session $checkoutSession,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly ManagerInterface $messages,
        private readonly RedirectFactory $redirectFactory
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $quote = $this->checkoutSession->getQuote();
        $points = $this->request->getParam('remove') ? 0 : max(0, (int) $this->request->getParam('loyalty_points'));

        if (!$quote->getId() || !$quote->getCustomerId()) {
            $this->messages->addErrorMessage(__('Please sign in and add a product to your cart to use your points.'));
        } else {
            $quote->setData('loyalty_points', $points);
            $this->quoteRepository->save($quote->collectTotals());
            $used = (int) $quote->getData('loyalty_points');
            if ($points && !$used) {
                $this->messages->addErrorMessage(__('You have no loyalty points to use.'));
            } else {
                $this->messages->addSuccessMessage(
                    $used ? __('%1 loyalty points are used on this order.', $used) : __('Loyalty points were removed.')
                );
            }
        }
        return $this->redirectFactory->create()->setPath('checkout/cart');
    }
}
