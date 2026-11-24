<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Test\Unit;

use MageOS\Loyalty\Model\Points;
use MageOS\Loyalty\Observer\Document;
use MageOS\Loyalty\Observer\Redeem;
use MageOS\Loyalty\Observer\Restore;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Tests the points earned, spent and given back around the order life cycle, on an in-memory ledger that
 * refuses, like the table's unique key, a second entry of the same type for the same order and document.
 */
class ObserversTest extends TestCase
{
    private const CUSTOMER = 7;
    private const ORDER = '100';

    /**
     * Ledger entries of the customer, as "type|order|document" => points.
     *
     * @var array<string, int>
     */
    private array $ledger = [];

    /**
     * A paid invoice earns floor(grand total × rate) points once it is saved, and only once.
     */
    public function testPaidInvoiceEarnsPointsOnceItIsSaved(): void
    {
        $invoice = $this->document(Invoice::class, $this->order(), 99.9, 3);
        $document = $this->documentObserver(1);

        $document->execute($this->event('sales_order_invoice_pay', ['invoice' => $invoice]));
        $this->assertSame([], $this->ledger, 'An invoice that is not saved earns nothing.');

        $document->execute($this->event('sales_order_invoice_save_after', ['invoice' => $invoice]));
        $document->execute($this->event('sales_order_invoice_save_after', ['invoice' => $invoice]));

        $this->assertSame(['earn|100|3' => 99], $this->ledger);
    }

    /**
     * An invoice saved without being paid in the request (a comment added to an old invoice) earns nothing.
     */
    public function testInvoiceSavedAgainEarnsNothing(): void
    {
        $invoice = $this->document(Invoice::class, $this->order(), 100.0, 3);

        $this->documentObserver(1)->execute($this->event('sales_order_invoice_save_after', ['invoice' => $invoice]));

        $this->assertSame([], $this->ledger);
    }

    /**
     * Guest orders earn nothing.
     */
    public function testGuestOrderEarnsNothing(): void
    {
        $invoice = $this->document(Invoice::class, $this->order([], null), 100.0, 3);
        $document = $this->documentObserver(1);

        $document->execute($this->event('sales_order_invoice_pay', ['invoice' => $invoice]));
        $document->execute($this->event('sales_order_invoice_save_after', ['invoice' => $invoice]));

        $this->assertSame([], $this->ledger);
    }

    /**
     * A credit memo takes back the refunded share of what the order earned, whatever the rate has become,
     * and the last one takes back what is left: never more than was earned.
     */
    public function testCreditMemosTakeBackWhatTheOrderEarnedAndNoMore(): void
    {
        $this->ledger = ['earn|100|3' => 100];
        $refunded = 0.0;
        $order = $this->order([], self::CUSTOMER, [], 100.0, $refunded);
        $document = $this->documentObserver(5);

        foreach ([[11, 40.0], [12, 30.0], [13, 30.0]] as [$id, $total]) {
            $refunded += $total;
            $creditmemo = $this->document(Creditmemo::class, $order, $total, $id);
            $document->execute($this->event('sales_order_creditmemo_refund', ['creditmemo' => $creditmemo]));
            $document->execute($this->event('sales_order_creditmemo_save_after', ['creditmemo' => $creditmemo]));
        }

        $this->assertSame(
            ['earn|100|3' => 100, 'reclaim|100|11' => -40, 'reclaim|100|12' => -30, 'reclaim|100|13' => -30],
            $this->ledger
        );
    }

    /**
     * Credit memos give back the points their amount was paid with, never more than the order spent:
     * two halves of one point give one point back, not two.
     */
    public function testCreditMemosNeverGiveBackMoreThanTheOrderSpent(): void
    {
        $this->ledger = ['spend|100|0' => -1];
        $order = $this->order(['loyalty_points' => 1, 'base_loyalty_amount' => 0.02]);
        $document = $this->documentObserver(0);

        foreach ([11, 12] as $id) {
            $creditmemo = $this->document(Creditmemo::class, $order, 10.0, $id, ['base_loyalty_amount' => 0.01]);
            $document->execute($this->event('sales_order_creditmemo_refund', ['creditmemo' => $creditmemo]));
            $document->execute($this->event('sales_order_creditmemo_save_after', ['creditmemo' => $creditmemo]));
        }

        $this->assertSame(['spend|100|0' => -1, 'refund|100|11' => 1], $this->ledger);
    }

    /**
     * An order placed before 1.1.0, unknown to the ledger, still gets its prorated points back on a refund.
     */
    public function testCreditMemoOfAnOrderTheLedgerDoesNotKnowGivesBackProratedPoints(): void
    {
        $order = $this->order(['loyalty_points' => 500, 'base_loyalty_amount' => 5.0]);
        $creditmemo = $this->document(Creditmemo::class, $order, 10.0, 11, ['base_loyalty_amount' => 2.0]);
        $document = $this->documentObserver(0);

        $document->execute($this->event('sales_order_creditmemo_refund', ['creditmemo' => $creditmemo]));
        $document->execute($this->event('sales_order_creditmemo_save_after', ['creditmemo' => $creditmemo]));

        $this->assertSame(['refund|100|11' => 200], $this->ledger);
    }

    /**
     * Before the order is placed the points are only noted: an error raised meanwhile has nothing to give back.
     * They are spent when the order is placed, and the amounts copied to it.
     */
    public function testPointsAreSpentOnlyWhenTheOrderIsPlaced(): void
    {
        $order = new DataObject(['increment_id' => self::ORDER]);
        $quote = new DataObject(
            ['customer_id' => self::CUSTOMER, 'loyalty_points' => 500, 'base_loyalty_amount' => 5.0]
        );
        $redeem = new Redeem($this->points(true));

        $redeem->execute(new Observer(['quote' => $quote, 'order' => $order]));
        $this->assertSame([], $this->ledger);
        $this->assertNull($order->getData('loyalty_points'));

        $redeem->execute(new Observer(['order' => $order]));
        $redeem->execute(new Observer(['order' => $order]));

        $this->assertSame(['spend|100|0' => -500], $this->ledger);
        $this->assertSame([500, 5.0], [$order->getData('loyalty_points'), $order->getData('base_loyalty_amount')]);
    }

    /**
     * When the balance no longer covers the points, the order is refused and carries no points.
     */
    public function testOrderIsRefusedWhenTheBalanceChanged(): void
    {
        $order = new DataObject(['increment_id' => self::ORDER]);
        $quote = new DataObject(
            ['customer_id' => self::CUSTOMER, 'loyalty_points' => 500, 'base_loyalty_amount' => 5.0]
        );
        $redeem = new Redeem($this->points(false));
        $redeem->execute(new Observer(['quote' => $quote, 'order' => $order]));

        try {
            $redeem->execute(new Observer(['order' => $order]));
            $this->fail('The order should be refused.');
        } catch (LocalizedException) {
            $this->assertNull($order->getData('loyalty_points'));
        }
    }

    /**
     * An order that could not be placed forgets its spent points: the retry uses the same order number.
     */
    public function testFailedCheckoutForgetsThePointsSpent(): void
    {
        $this->ledger = ['spend|100|0' => -500];

        $this->restore()->execute(new Observer(['order' => $this->order(), 'quote' => new DataObject()]));

        $this->assertSame([], $this->ledger);
    }

    /**
     * Core also reports a failed checkout after the order was saved (a later observer threw): the order
     * stays, paid with the points, so nothing is given back.
     */
    public function testFailureAfterTheOrderWasSavedGivesNothingBack(): void
    {
        $this->ledger = ['spend|100|0' => -500];
        $order = $this->order(['loyalty_points' => 500, 'base_loyalty_amount' => 5.0]);
        $order->method('getId')->willReturn(5);

        $this->restore('5')->execute(new Observer(['order' => $order, 'quote' => new DataObject()]));

        $this->assertSame(['spend|100|0' => -500], $this->ledger);
    }

    /**
     * A denied payment cancels the order without "order_cancel_after": the save of the newly canceled order
     * gives the points back, once; other saves give nothing.
     */
    public function testOrderSavedAsNewlyCanceledGivesBackOnce(): void
    {
        $this->ledger = ['spend|100|0' => -500];
        $order = $this->order(['loyalty_points' => 500, 'base_loyalty_amount' => 5.0]);
        $order->method('getState')->willReturn(Order::STATE_CANCELED, Order::STATE_CANCELED, Order::STATE_PROCESSING);
        $order->method('getOrigData')->willReturn(Order::STATE_PAYMENT_REVIEW);
        $save = $this->event('sales_order_save_before', ['order' => $order]);

        $restore = $this->restore();
        $restore->execute($save);
        $restore->execute($save);
        $restore->execute($save);

        $this->assertSame(['spend|100|0' => -500, 'return|100|0' => 500], $this->ledger);
    }

    /**
     * A canceled order gives back the points of the part not invoiced; the save that follows gives nothing more.
     */
    public function testCancelThenSaveGivesBackOnlyThePartNotInvoiced(): void
    {
        $this->ledger = ['spend|100|0' => -300];
        $paid = $this->createStub(Invoice::class);
        $paid->method('isCanceled')->willReturn(false);
        $paid->method('getData')->willReturnMap([['base_loyalty_amount', null, 2.0]]);
        $order = $this->order(['loyalty_points' => 300, 'base_loyalty_amount' => 3.0], self::CUSTOMER, [$paid]);
        $order->method('getState')->willReturn(Order::STATE_CANCELED);
        $order->method('getOrigData')->willReturn(Order::STATE_PROCESSING);

        $restore = $this->restore();
        foreach (['order_cancel_after', 'sales_order_save_before'] as $name) {
            $restore->execute($this->event($name, ['order' => $order]));
        }

        $this->assertSame(['spend|100|0' => -300, 'return|100|0' => 100], $this->ledger);
    }

    /**
     * The observers are wired to the events the fixes rely on.
     */
    public function testEventsAreWired(): void
    {
        $wired = [];
        $config = new \DOMDocument();
        $config->load(dirname(__DIR__, 2) . '/etc/events.xml');
        foreach ($config->getElementsByTagName('observer') as $observer) {
            $event = $observer->parentNode;
            if ($event instanceof \DOMElement) {
                $wired[] = $event->getAttribute('name') . ' ' . $observer->getAttribute('instance');
            }
        }

        $this->assertContains('sales_order_place_before ' . Redeem::class, $wired);
        $this->assertContains('sales_order_save_before ' . Restore::class, $wired);
        $this->assertContains('sales_order_invoice_save_after ' . Document::class, $wired);
        $this->assertContains('sales_order_creditmemo_save_after ' . Document::class, $wired);
    }

    /**
     * Observer argument of a named event.
     *
     * @param string $name
     * @param array<string, mixed> $data
     */
    private function event(string $name, array $data): Observer
    {
        return new Observer(['event' => new Event(['name' => $name])] + $data);
    }

    /**
     * Invoice and credit memo observer, with the given number of points earned per currency unit.
     *
     * @param int $rate
     */
    private function documentObserver(int $rate): Document
    {
        $config = $this->createStub(ScopeConfigInterface::class);
        $config->method('getValue')->willReturn((string) $rate);
        return new Document($this->points(true), $config);
    }

    /**
     * Order-level observer; the order is found in the database when $savedId is given.
     *
     * @param string|null $savedId
     */
    private function restore(?string $savedId = null): Restore
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn($savedId ?? false);
        $resource = $this->createStub(OrderResource::class);
        $resource->method('getConnection')->willReturn($connection);
        return new Restore($this->points(true), $resource);
    }

    /**
     * Points store on the in-memory ledger; spending succeeds or not.
     *
     * @param bool $spendSucceeds
     */
    private function points(bool $spendSucceeds): Points
    {
        $add = function (int $customerId, int $points, string $type, array $entry = []): bool {
            $key = $type . '|' . ($entry['order_number'] ?? '') . '|' . ($entry['document_id'] ?? 0);
            if (isset($this->ledger[$key])) {
                return false;
            }
            $this->ledger[$key] = $points;
            return true;
        };
        $points = $this->createStub(Points::class);
        $points->method('add')->willReturnCallback($add);
        $points->method('spend')->willReturnCallback(
            fn (int $customerId, int $points, string $type, array $entry = []): bool
                => $spendSucceeds && $add($customerId, -$points, $type, $entry)
        );
        $points->method('unspend')->willReturnCallback(function (int $customerId, string $number): void {
            unset($this->ledger['spend|' . $number . '|0']);
        });
        $points->method('getOrderPoints')->willReturnCallback(function (int $customerId, string $number): array {
            $sums = [];
            foreach ($this->ledger as $key => $value) {
                [$type, $order] = explode('|', $key);
                if ($order === $number) {
                    $sums[$type] = ($sums[$type] ?? 0) + $value;
                }
            }
            return $sums;
        });
        return $points;
    }

    /**
     * Order "100" of the customer (null for a guest).
     *
     * @param array<string, int|float> $data
     * @param int|null $customerId
     * @param Invoice[] $invoices
     * @param float $invoiced base total invoiced
     * @param float $refunded base total refunded, read when asked: by reference
     * @return Order&Stub
     */
    private function order(
        array $data = [],
        ?int $customerId = self::CUSTOMER,
        array $invoices = [],
        float $invoiced = 0.0,
        float &$refunded = 0.0
    ): Order {
        $order = $this->createStub(Order::class);
        $order->method('getCustomerId')->willReturn($customerId);
        $order->method('getIncrementId')->willReturn(self::ORDER);
        $order->method('getData')->willReturnCallback(fn ($key) => $data[$key] ?? null);
        $order->method('getInvoiceCollection')->willReturn($invoices);
        $order->method('getBaseTotalInvoiced')->willReturn($invoiced);
        $order->method('getBaseTotalRefunded')->willReturnCallback(function () use (&$refunded): float {
            return $refunded;
        });
        return $order;
    }

    /**
     * Invoice or credit memo of the order, keeping the data set on it.
     *
     * @template T of Invoice|Creditmemo
     * @param class-string<T> $class
     * @param Order $order
     * @param float $baseGrandTotal
     * @param int $id
     * @param array<string, mixed> $data
     * @return T&Stub
     */
    private function document(string $class, Order $order, float $baseGrandTotal, int $id, array $data = [])
    {
        $document = $this->createStub($class);
        $document->method('getOrder')->willReturn($order);
        $document->method('getBaseGrandTotal')->willReturn($baseGrandTotal);
        $document->method('getId')->willReturn($id);
        $document->method('getData')->willReturnCallback(function ($key) use (&$data) {
            return $data[$key] ?? null;
        });
        $document->method('setData')->willReturnCallback(function ($key, $value) use (&$data, $document) {
            $data[$key] = $value;
            return $document;
        });
        return $document;
    }
}
