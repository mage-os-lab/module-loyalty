<?php
declare(strict_types=1);

namespace MageOS\Loyalty\Setup\Patch\Data;

use MageOS\Loyalty\Model\Points;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\File\Csv;
use Magento\Framework\Module\Dir;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Gives the ledger entries written by 1.0 their type and order number.
 *
 * 1.0 stored a translated text ("Order #000000012", "Commande n° 000000012"…) instead: it is matched against
 * the module's own translations. An entry whose text matches none (translation changed by the store) is left
 * as it is, an adjustment with its comment; its order is then handled as one the ledger does not know.
 */
class TypeLedgerEntries implements DataPatchInterface
{
    private const PHRASES = [
        'Order #%1' => Points::EARN,
        'Refund of order #%1' => Points::RECLAIM,
        'Spent on order #%1' => Points::SPEND,
        'Points returned for order #%1' => Points::REFUND,
    ];

    /**
     * @param ModuleDataSetupInterface $setup
     * @param Dir $moduleDir
     * @param Csv $csv
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $setup,
        private readonly Dir $moduleDir,
        private readonly Csv $csv
    ) {
    }

    /**
     * One update per phrase and language.
     *
     * Migrated entries get a negative document ID, their own: several invoices of one order must not collide
     * on the unique key, nor with the IDs of documents to come.
     */
    public function apply(): self
    {
        $connection = $this->setup->getConnection();
        $table = $this->setup->getTable('mageos_loyalty_points');
        $dir = $this->moduleDir->getDir('MageOS_Loyalty', Dir::MODULE_I18N_DIR);
        foreach (['en_US', 'fr_FR', 'de_DE', 'es_ES', 'it_IT'] as $locale) {
            $translations = $this->csv->getDataPairs($dir . '/' . $locale . '.csv');
            foreach (self::PHRASES as $phrase => $type) {
                [$before, $after] = explode('%1', (string) ($translations[$phrase] ?? $phrase)) + [1 => ''];
                $connection->update(
                    $table,
                    [
                        'type' => $type,
                        'order_number' => new Expression(sprintf(
                            'SUBSTRING(comment, %d, CHAR_LENGTH(comment) - %d)',
                            mb_strlen($before) + 1,
                            mb_strlen($before . $after)
                        )),
                        'document_id' => new Expression('-entry_id'),
                        // last: MySQL assigns in this order, and the order number is read from the comment
                        'comment' => null,
                    ],
                    [
                        'type = ?' => Points::ADJUST,
                        'order_number IS NULL',
                        'comment LIKE ?' => $this->like($before) . '_%' . $this->like($after),
                    ]
                );
            }
        }
        return $this;
    }

    /**
     * Escape the wildcards of a LIKE pattern.
     *
     * @param string $text
     */
    private function like(string $text): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text);
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
