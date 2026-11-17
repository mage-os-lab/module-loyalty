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
        $points->add($customerId, 100, 'Order #1');

        $this->assertFalse($points->spend($customerId, 150, 'Order #2'));
        $this->assertTrue($points->spend($customerId, 40, 'Order #3'));
        $this->assertSame(60, $points->getBalance($customerId));
        $this->assertSame(['-40', '100'], array_column($points->getHistory($customerId), 'points'));
    }
}
