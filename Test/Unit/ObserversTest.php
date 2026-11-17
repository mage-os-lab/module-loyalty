<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Test\Unit;

use MageOS\Loyalty\Model\Points;
use MageOS\Loyalty\Observer\Earn;
use MageOS\Loyalty\Observer\Redeem;
use MageOS\Loyalty\Observer\Restore;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use PHPUnit\Framework\TestCase;

/**
 * Tests the points earned, spent and given back around the order life cycle.
 */
class ObserversTest extends TestCase
{
    /**
     * Ledger entries written on the fake points store, as [customer ID, points].
     *
     * @var array<int, array{int, int}>
     */
    private array $entries = [];

    /**
     * A paid invoice earns floor(grand total × rate) points.
     */
    public function testPaidInvoiceEarnsPointsOnItsGrandTotal(): void
    {
        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getOrder')->willReturn($this->order(7));
        $invoice->method('getBaseGrandTotal')->willReturn(99.9);

        $this->earn()->execute(new Observer(['invoice' => $invoice]));

        $this->assertSame([[7, 99]], $this->entries);
    }

    /**
     * A credit memo takes back the points its grand total earned.
     */
    public function testCreditMemoTakesBackThePointsEarned(): void
    {
        $creditmemo = $this->createStub(Creditmemo::class);
        $creditmemo->method('getOrder')->willReturn($this->order(7));
        $creditmemo->method('getBaseGrandTotal')->willReturn(40.0);

        $this->earn()->execute(new Observer(['creditmemo' => $creditmemo]));

        $this->assertSame([[7, -40]], $this->entries);
    }

    /**
     * Guest orders earn nothing.
     */
    public function testGuestOrderEarnsNothing(): void
    {
        $invoice = $this->createStub(Invoice::class);
        $invoice->method('getOrder')->willReturn($this->order(null));
        $invoice->method('getBaseGrandTotal')->willReturn(100.0);

        $this->earn()->execute(new Observer(['invoice' => $invoice]));

        $this->assertSame([], $this->entries);
    }

    /**
     * When the balance no longer covers the points, the order is refused and carries no points to give back.
     */
    public function testOrderIsRefusedWhenTheBalanceChanged(): void
    {
        $order = new DataObject(['increment_id' => '100']);
        $quote = new DataObject(['customer_id' => 7, 'loyalty_points' => 500, 'base_loyalty_amount' => 5.0]);

        try {
            (new Redeem($this->points(false)))->execute(new Observer(['quote' => $quote, 'order' => $order]));
            $this->fail('The order should be refused.');
        } catch (LocalizedException $e) {
            $this->assertNull($order->getData('loyalty_points'));
        }
    }

    /**
     * A refund gives back points prorated on the refunded amount.
     */
    public function testCreditMemoGivesBackProratedPoints(): void
    {
        $order = $this->order(7, ['loyalty_points' => 500, 'base_loyalty_amount' => 5.0]);
        $creditmemo = $this->createStub(Creditmemo::class);
        $creditmemo->method('getOrder')->willReturn($order);
        $creditmemo->method('getData')->willReturnMap([['base_loyalty_amount', null, 2.0]]);

        (new Restore($this->points(true)))->execute(new Observer(['creditmemo' => $creditmemo]));

        $this->assertSame([[7, 200]], $this->entries);
    }

    /**
     * Earn observer with a rate of one point per currency unit.
     *
     * @return Earn
     */
    private function earn(): Earn
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn('1');
        return new Earn($this->points(true), $config);
    }

    /**
     * Fake points store: spending succeeds or not, ledger entries are recorded.
     *
     * @param bool $spendSucceeds
     * @return Points
     */
    private function points(bool $spendSucceeds): Points
    {
        $points = $this->createStub(Points::class);
        $points->method('spend')->willReturn($spendSucceeds);
        $points->method('add')->willReturnCallback(function (int $customerId, int $points): void {
            $this->entries[] = [$customerId, $points];
        });
        return $points;
    }

    /**
     * Order of the given customer (null for a guest).
     *
     * @param int|null $customerId
     * @param array<string,int|float> $data
     * @return Order
     */
    private function order(?int $customerId, array $data = []): Order
    {
        $order = $this->createStub(Order::class);
        $order->method('getCustomerId')->willReturn($customerId);
        $order->method('getIncrementId')->willReturn('100');
        $order->method('getData')->willReturnCallback(fn ($key) => $data[$key] ?? null);
        $order->method('getInvoiceCollection')->willReturn([]);
        return $order;
    }
}
