<?php
/**
 * Which products match each label's conditions, kept in kkkonrad_product_label_product.
 *
 * The conditions are core's catalog price rule conditions, so a throwaway
 * CatalogRule\Model\Rule does all the work: same admin tree, same attribute
 * list, same matcher. It is never saved.
 *
 * Refreshed on label save, on product save, and nightly by cron for whatever
 * bypasses the save event (imports, mass attribute update).
 */

declare(strict_types=1);

namespace Kkkonrad\ProductLabels\Model;

use Magento\Catalog\Model\Product;
use Magento\CatalogRule\Model\Rule;
use Magento\CatalogRule\Model\RuleFactory;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

class Indexer
{
    public const LABEL_TABLE = 'kkkonrad_product_label';
    public const INDEX_TABLE = 'kkkonrad_product_label_product';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly RuleFactory $ruleFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly Configurable $configurable
    ) {
    }

    public function reindexAll(): void
    {
        $connection = $this->resource->getConnection();
        $ids = $connection->fetchCol(
            $connection->select()->from($this->resource->getTableName(self::LABEL_TABLE), 'label_id')
        );
        foreach ($ids as $id) {
            $this->reindex((int)$id);
        }
    }

    public function reindex(int $labelId): void
    {
        $connection = $this->resource->getConnection();
        $serialized = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName(self::LABEL_TABLE), 'conditions_serialized')
                ->where('label_id = ?', $labelId)
        );
        $rule = $this->rule($serialized ?: null);

        $ids = [];
        // ponytail: matches in the default store view of each website; store-scoped attribute values are not seen per store
        foreach ($rule ? $rule->getMatchingProductIds() : [] as $productId => $websites) {
            if (array_filter($websites)) {
                $ids[] = (int)$productId;
            }
        }
        /*
         * In adminhtml and crontab, core's CatalogRuleConfigurable plugin swaps
         * a matching configurable for its children. Listings show the parent,
         * so put it back - which also labels a parent when any variant matches.
         */
        $ids = array_unique(array_merge($ids, array_map('intval', $this->configurable->getParentIdsByChild($ids))));
        $rows = array_map(fn (int $id) => ['label_id' => $labelId, 'product_id' => $id], $ids);

        $table = $this->resource->getTableName(self::INDEX_TABLE);
        $connection->beginTransaction();
        try {
            $connection->delete($table, ['label_id = ?' => $labelId]);
            foreach (array_chunk($rows, 1000) as $chunk) {
                $connection->insertMultiple($table, $chunk);
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    public function reindexProduct(Product $product): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::INDEX_TABLE);
        $labels = $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName(self::LABEL_TABLE), ['label_id', 'conditions_serialized'])
                ->where('conditions_serialized IS NOT NULL')
        );
        foreach ($labels as $labelId => $serialized) {
            $rule = $this->rule($serialized);
            $where = ['label_id = ?' => (int)$labelId, 'product_id = ?' => (int)$product->getId()];
            if ($rule && $rule->getConditions()->validate($product)) {
                $connection->insertOnDuplicate($table, ['label_id' => (int)$labelId, 'product_id' => (int)$product->getId()]);
            } else {
                $connection->delete($table, $where);
            }
        }
    }

    /**
     * @return Rule|null null when there are no conditions: the label applies to every product
     */
    public function rule(?string $serialized): ?Rule
    {
        if (!$serialized) {
            return null;
        }
        $rule = $this->ruleFactory->create();
        $rule->setConditionsSerialized($serialized);
        $rule->setWebsiteIds(array_keys($this->storeManager->getWebsites()));
        return $rule->getConditions()->getConditions() ? $rule : null;
    }
}
