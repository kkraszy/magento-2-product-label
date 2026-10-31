<?php
/**
 * The labels one product gets on the storefront, grouped by position.
 *
 * Store, date and customer group are filtered here, per request; the rule
 * conditions come pre-computed from the index. "New", "on sale" and stock
 * depend on today and on prices, so they are checked live.
 */

declare(strict_types=1);

namespace Kkkonrad\ProductLabels\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Catalog\Pricing\Price\RegularPrice;
use Magento\Customer\Model\Context as CustomerContext;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

class Labels implements ArgumentInterface
{
    private ?array $active = null;

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly HttpContext $httpContext,
        private readonly TimezoneInterface $timezone,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly Json $json
    ) {
    }

    /**
     * @param string $area 'listing' or 'product'
     * @return array<string, list<array>> position => labels, each with its final `text`
     */
    public function forProduct(Product $product, string $area): array
    {
        $result = [];
        $shown = false;
        $indexed = $vars = null;
        foreach ($this->active() as $label) {
            if (!$label['show_on_' . $area] || ($shown && $label['hide_if_higher'])) {
                continue;
            }
            if ($label['has_conditions']) {
                $indexed ??= $this->indexedLabelIds((int)$product->getId());
                if (!isset($indexed[$label['label_id']])) {
                    continue;
                }
            }
            $vars ??= $this->vars($product);
            if (!$this->matches($label, $product, $vars)) {
                continue;
            }
            $text = self::text($label['text'], $vars);
            if ($text === null) {
                continue;
            }
            $result[$label['position']][] = ['text' => $text] + $label;
            $shown = true;
        }
        return $result;
    }

    /**
     * Swap the variables into the label text.
     *
     * @param array<string, string|null> $vars a null value means "not applicable here"
     * @return string|null null when the text uses a variable that does not apply, e.g. {SAVE_PERCENT} at full price
     */
    public static function text(string $template, array $vars): ?string
    {
        foreach ($vars as $name => $value) {
            if ($value === null && str_contains($template, $name)) {
                return null;
            }
        }
        return strtr($template, array_filter($vars, 'is_string'));
    }

    private function matches(array $label, Product $product, array $vars): bool
    {
        if ($label['only_sale'] && $vars['{SAVE_PERCENT}'] === null) {
            return false;
        }
        if ($label['stock_status'] && ($label['stock_status'] === 'in') !== (bool)$product->isSalable()) {
            return false;
        }
        if ($label['only_new']) {
            $today = $this->timezone->date()->format('Y-m-d');
            $from = substr((string)$product->getData('news_from_date'), 0, 10);
            $to = substr((string)$product->getData('news_to_date'), 0, 10);
            // Core's "Set Product as New" needs a start date; an open end means new for good
            if (!$from || $from > $today || ($to && $to < $today)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<string, string|null>
     */
    private function vars(Product $product): array
    {
        $priceInfo = $product->getPriceInfo();
        $regular = (float)$priceInfo->getPrice(RegularPrice::PRICE_CODE)->getAmount()->getValue();
        $final = (float)$priceInfo->getPrice(FinalPrice::PRICE_CODE)->getAmount()->getValue();
        $percent = $regular > 0 && $final > 0 && $final < $regular ? (int)round(100 - $final / $regular * 100) : 0;
        return [
            '{SAVE_PERCENT}' => $percent > 0 ? (string)$percent : null,
            '{SAVE_AMOUNT}' => $percent > 0 ? $this->priceCurrency->format($regular - $final, false) : null,
            '{PRICE}' => $final > 0 ? $this->priceCurrency->format($final, false) : null,
            '{SKU}' => (string)$product->getSku(),
        ];
    }

    private function active(): array
    {
        if ($this->active !== null) {
            return $this->active;
        }
        $connection = $this->resource->getConnection();
        $today = $this->timezone->date()->format('Y-m-d');
        $storeId = (int)$this->storeManager->getStore()->getId();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName(Indexer::LABEL_TABLE))
                ->where('is_active = 1')
                ->where('from_date IS NULL OR from_date <= ?', $today)
                ->where('to_date IS NULL OR to_date >= ?', $today)
                ->where('FIND_IN_SET(0, store_ids) OR FIND_IN_SET(?, store_ids)', $storeId)
                ->order(['priority ASC', 'label_id ASC'])
        );

        // Varies the full page cache already, unlike the session, which is emptied before cached pages render
        $groupId = (string)(int)$this->httpContext->getValue(CustomerContext::CONTEXT_GROUP);
        $this->active = [];
        foreach ($rows as $row) {
            if ($row['customer_group_ids'] !== '' && !in_array($groupId, explode(',', $row['customer_group_ids']), true)) {
                continue;
            }
            $conditions = $row['conditions_serialized'] ? $this->json->unserialize($row['conditions_serialized']) : [];
            $row['has_conditions'] = !empty($conditions['conditions']);
            $this->active[] = $row;
        }
        return $this->active;
    }

    /**
     * @return array<int, true>
     */
    private function indexedLabelIds(int $productId): array
    {
        $connection = $this->resource->getConnection();
        // ponytail: one query per product with a conditional label; prefetch per collection if listings get long
        $ids = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName(Indexer::INDEX_TABLE), 'label_id')
                ->where('product_id = ?', $productId)
        );
        return array_fill_keys(array_map('intval', $ids), true);
    }
}
