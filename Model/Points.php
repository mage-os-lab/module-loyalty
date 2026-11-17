<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Throwable;

/**
 * Points ledger: the balance is the sum of a customer's entries.
 */
class Points
{
    /**
     * @param ResourceConnection $resource
     * @param ScopeConfigInterface $config
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ScopeConfigInterface $config
    ) {
    }

    /**
     * Current balance of the customer.
     *
     * @param int $customerId
     */
    public function getBalance(int $customerId): int
    {
        $connection = $this->resource->getConnection();
        return (int) $connection->fetchOne(
            $connection->select()->from($this->table(), 'SUM(points)')->where('customer_id = ?', $customerId)
        );
    }

    /**
     * Add a ledger entry (negative points to take some away).
     *
     * @param int $customerId
     * @param int $points
     * @param string $comment
     */
    public function add(int $customerId, int $points, string $comment): void
    {
        $this->resource->getConnection()->insert(
            $this->table(),
            ['customer_id' => $customerId, 'points' => $points, 'comment' => $comment]
        );
    }

    /**
     * Atomic: returns false, and changes nothing, when the balance does not cover the points.
     *
     * A row lock on the customer stops two concurrent orders from both passing the balance check.
     *
     * @param int $customerId
     * @param int $points
     * @param string $comment
     */
    public function spend(int $customerId, int $points, string $comment): bool
    {
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $connection->fetchOne(
                $connection->select()->from($this->resource->getTableName('customer_entity'), 'entity_id')
                    ->where('entity_id = ?', $customerId)->forUpdate()
            );
            $enough = $points > 0 && $points <= $this->getBalance($customerId);
            if ($enough) {
                $this->add($customerId, -$points, $comment);
            }
            $connection->commit();
            return $enough;
        } catch (Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Latest 50 entries of the customer.
     *
     * @param int $customerId
     * @return array<int, array{points: string, comment: ?string, created_at: string}> latest first
     */
    public function getHistory(int $customerId): array
    {
        $connection = $this->resource->getConnection();
        return $connection->fetchAll(
            $connection->select()->from($this->table(), ['points', 'comment', 'created_at'])
                ->where('customer_id = ?', $customerId)->order('entry_id DESC')->limit(50)
        );
    }

    /**
     * Conversion rate set in the admin: base currency amount one point is worth.
     */
    public function getPointValue(): float
    {
        return max(0.0, (float) $this->config->getValue('mageos_loyalty/general/point_value'));
    }

    /**
     * Ledger table name.
     */
    private function table(): string
    {
        return $this->resource->getTableName('mageos_loyalty_points');
    }
}
