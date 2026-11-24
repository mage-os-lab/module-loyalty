<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Controller\Adminhtml\Points;

use MageOS\Loyalty\Model\Points;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Adds points to a customer's balance, or takes some away, with a comment and the admin user's name.
 */
class Adjust extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'MageOS_Loyalty::points_adjust';
    private const MAX_POINTS = 1000000000;

    /**
     * @param Context $context
     * @param Points $points
     * @param CustomerRepositoryInterface $customerRepository
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context $context,
        private readonly Points $points,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    /**
     * Points taken away are capped by the balance, checked and written under the same lock as an order's.
     */
    public function execute()
    {
        $request = $this->getRequest();
        $points = $request->getParam('points');
        $comment = mb_substr(trim((string) $request->getParam('comment')), 0, 255);
        try {
            if (!is_numeric($points) || (int) $points != $points || !(int) $points
                || abs((int) $points) > self::MAX_POINTS
            ) {
                throw new LocalizedException(__('Enter a whole number of points, other than 0.'));
            }
            if ($comment === '') {
                throw new LocalizedException(__('Enter a comment: the customer sees it in the points history.'));
            }
            $customer = $this->customer(trim((string) $request->getParam('customer')));
            $customerId = (int) $customer->getId();
            $user = $this->_auth->getUser();
            $entry = [
                'comment' => $comment,
                'admin_user' => $user instanceof DataObject ? (string) $user->getData('username') : null,
            ];
            $points = (int) $points;
            if ($points > 0) {
                $this->points->add($customerId, $points, Points::ADJUST, $entry);
            } elseif (!$this->points->spend($customerId, -$points, Points::ADJUST, $entry)) {
                throw new LocalizedException(__(
                    '%1 has only %2 points: nothing was taken away.',
                    $customer->getEmail(),
                    $this->points->getBalance($customerId)
                ));
            }
            $this->messageManager->addSuccessMessage(__(
                'The balance of %1 is now %2 points.',
                $customer->getEmail(),
                $this->points->getBalance($customerId)
            ));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }
        return $this->resultRedirectFactory->create()->setPath('*/*/');
    }

    /**
     * The customer of an ID, or of an e-mail address when a single account uses it.
     *
     * @param string $input
     * @throws LocalizedException
     */
    private function customer(string $input): CustomerInterface
    {
        $found = [];
        try {
            if (ctype_digit($input)) {
                return $this->customerRepository->getById((int) $input);
            }
        } catch (NoSuchEntityException) {
            $input = '';
        }
        foreach ($input === '' ? [] : $this->storeManager->getWebsites() as $website) {
            try {
                $customer = $this->customerRepository->get($input, (int) $website->getId());
                $found[(int) $customer->getId()] = $customer;
            } catch (NoSuchEntityException) {
                continue;
            }
        }
        if (count($found) > 1) {
            throw new LocalizedException(
                __('Several accounts use this e-mail address: enter the customer ID instead.')
            );
        }
        return reset($found) ?: throw new LocalizedException(__('No customer has this e-mail address or ID.'));
    }
}
