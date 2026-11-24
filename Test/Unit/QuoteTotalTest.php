<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Test\Unit;

use MageOS\Loyalty\Model\Points;
use MageOS\Loyalty\Model\Total\Quote as QuoteTotal;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Total;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the loyalty points quote total collector.
 */
class QuoteTotalTest extends TestCase
{
    /**
     * Requested points, balance, rate, grand total => grand total left, amount, points spent.
     *
     * @return array<string, array{0: int, 1: int, 2: float, 3: float, 4: float, 5: float, 6: ?int, 7?: bool}>
     */
    public static function cases(): array
    {
        return [
            'all requested points' => [500, 1000, 0.01, 100.0, 95.0, 5.0, 500],
            'capped by balance' => [5000, 300, 0.01, 100.0, 97.0, 3.0, 300],
            'capped by grand total' => [900, 900, 0.5, 100.0, 0.0, 100.0, 200],
            'rate of zero' => [500, 1000, 0.0, 100.0, 100.0, 0.0, null],
            'nothing requested' => [0, 1000, 0.01, 100.0, 100.0, 0.0, null],
            'points worth less than a cent pay nothing' => [5, 5, 0.001, 100.0, 100.0, 0.0, null],
            'amount rounded down, never more points than asked' => [15, 15, 0.001, 100.0, 99.99, 0.01, 10],
            'negative balance (refund of spent points) adds nothing' => [500, -40, 0.01, 100.0, 100.0, 0.0, null],
            'multi-address checkout deducts nothing' => [500, 1000, 0.01, 100.0, 100.0, 0.0, 500, true],
        ];
    }

    /**
     * Points are capped by the balance and the grand total, and only the points actually used are kept;
     * the multi-address checkout uses none.
     */
    #[DataProvider('cases')]
    public function testCollect(
        int $requested,
        int $balance,
        float $rate,
        float $grandTotal,
        float $expectedTotal,
        float $expectedAmount,
        ?int $expectedPoints,
        bool $multiShipping = false
    ): void {
        $points = $this->createStub(Points::class);
        $points->method('getBalance')->willReturn($balance);
        $points->method('getPointValue')->willReturn($rate);
        $priceCurrency = $this->createStub(PriceCurrencyInterface::class);
        $priceCurrency->method('convertAndRound')->willReturnArgument(0);
        $assignment = $this->createStub(ShippingAssignmentInterface::class);
        $assignment->method('getItems')->willReturn(['item']);

        $data = ['loyalty_points' => $requested, 'customer_id' => 1, 'is_multi_shipping' => $multiShipping];
        $quote = $this->createStub(Quote::class);
        $quote->method('getData')->willReturnCallback(fn ($key) => $data[$key] ?? null);
        $quote->method('setData')->willReturnCallback(function ($key, $value) use (&$data, $quote) {
            $data[$key] = $value;
            return $quote;
        });
        $total = new Total([], new Json());
        $total->setBaseGrandTotal($grandTotal)->setGrandTotal($grandTotal);

        (new QuoteTotal($points, $priceCurrency))->collect($quote, $assignment, $total);

        $this->assertSame($expectedTotal, (float) $total->getBaseGrandTotal());
        $this->assertSame($expectedAmount, $data['base_loyalty_amount']);
        $this->assertSame($expectedAmount, $data['loyalty_amount']);
        $this->assertSame($expectedPoints, $data['loyalty_points']);
    }
}
