<?php
declare(strict_types=1);

namespace MageOS\Loyalty\ViewModel;

use MageOS\Loyalty\Model\Points;
use Magento\Customer\Model\Session;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Points balance and history of the logged-in customer.
 */
class Account implements ArgumentInterface
{
    /**
     * @param Points $points
     * @param Session $customerSession
     * @param PriceCurrencyInterface $priceCurrency
     */
    public function __construct(
        private readonly Points $points,
        private readonly Session $customerSession,
        private readonly PriceCurrencyInterface $priceCurrency
    ) {
    }

    /**
     * Points balance of the logged-in customer.
     */
    public function getBalance(): int
    {
        return $this->points->getBalance((int) $this->customerSession->getCustomerId());
    }

    /**
     * Latest ledger entries of the logged-in customer.
     *
     * @return array<int, array<string, ?string>>
     */
    public function getHistory(): array
    {
        return $this->points->getHistory((int) $this->customerSession->getCustomerId());
    }

    /**
     * What a ledger entry is for, in the customer's language.
     *
     * @param array<string,?string> $entry
     */
    public function describe(array $entry): string
    {
        return $this->points->describe($entry);
    }

    /**
     * Formatted value of the given number of points, in the current currency.
     *
     * @param int $points
     */
    public function getValue(int $points): string
    {
        return $this->priceCurrency->convertAndFormat($points * $this->points->getPointValue(), false);
    }
}
