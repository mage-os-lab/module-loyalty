<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Test\Integration;

use MageOS\Loyalty\Model\Points;
use Magento\Customer\Test\Fixture\Customer as CustomerFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Tests the points ledger against the database.
 */
#[DbIsolation(true)]
class PointsTest extends TestCase
{
    /**
     * Spending more than the balance changes nothing; the balance is the sum of the entries.
     */
    #[DataFixture(CustomerFixture::class, as: 'customer')]
    public function testSpendingNeverGoesBelowZero(): void
    {
        $customerId = (int) DataFixtureStorageManager::getStorage()->get('customer')->getId();
        $points = Bootstrap::getObjectManager()->get(Points::class);
        $points->add($customerId, 100, Points::EARN, ['order_number' => '1', 'document_id' => 1]);

        $this->assertFalse($points->spend($customerId, 150, Points::SPEND, ['order_number' => '2']));
        $this->assertTrue($points->spend($customerId, 40, Points::SPEND, ['order_number' => '3']));
        $this->assertSame(60, $points->getBalance($customerId));
        $this->assertSame(['-40', '100'], array_column($points->getHistory($customerId), 'points'));
    }

    /**
     * The same entry (type, order, document) is written once; a failed checkout frees its order number.
     */
    #[DataFixture(CustomerFixture::class, as: 'customer')]
    public function testAnEntryIsWrittenOncePerOrderAndDocument(): void
    {
        $customerId = (int) DataFixtureStorageManager::getStorage()->get('customer')->getId();
        $points = Bootstrap::getObjectManager()->get(Points::class);
        $invoice = ['order_number' => '1', 'document_id' => 5];

        $this->assertTrue($points->add($customerId, 100, Points::EARN, $invoice));
        $this->assertFalse($points->add($customerId, 100, Points::EARN, $invoice));
        $this->assertTrue($points->add($customerId, 10, Points::ADJUST, ['comment' => 'Goodwill']));
        $this->assertTrue($points->add($customerId, 10, Points::ADJUST, ['comment' => 'Goodwill']));
        $this->assertTrue($points->spend($customerId, 30, Points::SPEND, ['order_number' => '2']));
        $this->assertSame(90, $points->getBalance($customerId));

        $points->unspend($customerId, '2');
        $this->assertTrue($points->spend($customerId, 30, Points::SPEND, ['order_number' => '2']));
        $this->assertSame([Points::SPEND => -30], $points->getOrderPoints($customerId, '2'));
    }
}
