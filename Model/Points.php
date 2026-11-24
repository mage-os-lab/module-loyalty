<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Throwable;

/**
 * Points ledger: the balance is the sum of a customer's entries.
 *
 * Each entry has a type. Entries made for an order carry its number (increment ID: the order has no ID yet when
 * its points are spent) and, for an invoice or a credit memo, the ID of that document. The unique key on
 * customer, type, order and document makes writing the same entry twice a no-op.
 */
class Points
{
    public const EARN = 'earn';
    public const RECLAIM = 'reclaim';
    public const SPEND = 'spend';
    public const RETURN = 'return';
    public const REFUND = 'refund';
    public const ADJUST = 'adjust';

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
     * Negative when a refund took back points the customer had already spent: the next points earned fill it.
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
     * The type is one of the constants of this class; the entry may hold "order_number", "document_id",
     * "comment" and "admin_user". Returns false, and writes nothing, when the ledger already has this entry
     * (same type, order and document).
     *
     * @param int $customerId
     * @param int $points
     * @param string $type
     * @param array<string,string|int|null> $entry
     */
    public function add(int $customerId, int $points, string $type, array $entry = []): bool
    {
        $row = ['customer_id' => $customerId, 'points' => $points, 'type' => $type] + $entry;
        // on the unique key, "update" the customer to itself: no row is changed, and none is counted
        return (bool) $this->resource->getConnection()->insertOnDuplicate($this->table(), $row, ['customer_id']);
    }

    /**
     * Atomic: returns false, and changes nothing, when the balance does not cover the points.
     *
     * A row lock on the customer stops two concurrent orders from both passing the balance check.
     *
     * @param int $customerId
     * @param int $points
     * @param string $type
     * @param array<string,string|int|null> $entry
     */
    public function spend(int $customerId, int $points, string $type, array $entry = []): bool
    {
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $connection->fetchOne(
                $connection->select()->from($this->resource->getTableName('customer_entity'), 'entity_id')
                    ->where('entity_id = ?', $customerId)->forUpdate()
            );
            $done = $points > 0 && $points <= $this->getBalance($customerId)
                && $this->add($customerId, -$points, $type, $entry);
            $connection->commit();
            return $done;
        } catch (Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * Forget the points spent on an order that was not placed: its number is used again when the customer retries.
     *
     * @param int $customerId
     * @param string $orderNumber
     */
    public function unspend(int $customerId, string $orderNumber): void
    {
        $this->resource->getConnection()->delete($this->table(), [
            'customer_id = ?' => $customerId,
            'type = ?' => self::SPEND,
            'order_number = ?' => $orderNumber,
        ]);
    }

    /**
     * Points written for an order, summed by entry type.
     *
     * @param int $customerId
     * @param string $orderNumber
     * @return array<string, int> empty for an order the ledger does not know (placed before 1.1.0)
     */
    public function getOrderPoints(int $customerId, string $orderNumber): array
    {
        $connection = $this->resource->getConnection();
        return array_map('intval', $connection->fetchPairs(
            $connection->select()->from($this->table(), ['type', 'SUM(points)'])
                ->where('customer_id = ?', $customerId)->where('order_number = ?', $orderNumber)->group('type')
        ));
    }

    /**
     * Latest 50 entries of the customer.
     *
     * @param int $customerId
     * @return array<int, array<string, ?string>> latest first
     */
    public function getHistory(int $customerId): array
    {
        $connection = $this->resource->getConnection();
        return $connection->fetchAll(
            $connection->select()
                ->from($this->table(), ['points', 'type', 'order_number', 'comment', 'created_at'])
                ->where('customer_id = ?', $customerId)->order('entry_id DESC')->limit(50)
        );
    }

    /**
     * What an entry is for, in the language of the reader: entries store a type, not a text.
     *
     * @param array<string,mixed> $entry
     */
    public function describe(array $entry): string
    {
        $number = (string) ($entry['order_number'] ?? '');
        return (string) match ($number === '' ? self::ADJUST : ($entry['type'] ?? self::ADJUST)) {
            self::EARN => __('Order #%1', $number),
            self::RECLAIM => __('Refund of order #%1', $number),
            self::SPEND => __('Spent on order #%1', $number),
            self::RETURN, self::REFUND => __('Points returned for order #%1', $number),
            default => $entry['comment'] ?? '',
        };
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
