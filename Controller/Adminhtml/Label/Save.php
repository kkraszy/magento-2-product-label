<?php
/**
 * Every posted value is checked against what the form offers before it is
 * written: colours end up inside a style attribute on the storefront.
 */

declare(strict_types=1);

namespace Kkkonrad\ProductLabels\Controller\Adminhtml\Label;

use Kkkonrad\ProductLabels\Block\Adminhtml\Label\Form;
use Kkkonrad\ProductLabels\Model\Indexer;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\CatalogRule\Model\RuleFactory;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Serialize\Serializer\Json;

class Save extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Kkkonrad_ProductLabels::labels';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly RuleFactory $ruleFactory,
        private readonly Json $json,
        private readonly Indexer $indexer,
        private readonly TypeListInterface $cacheTypeList
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $post = $this->getRequest()->getPostValue();
        $id = (int)($post['label_id'] ?? 0);
        $redirect = $this->resultRedirectFactory->create();

        $row = [
            'name' => trim((string)($post['name'] ?? '')),
            'text' => trim((string)($post['text'] ?? '')),
            'is_active' => (int)!empty($post['is_active']),
            'priority' => (int)($post['priority'] ?? 0),
            'hide_if_higher' => (int)!empty($post['hide_if_higher']),
            'store_ids' => implode(',', array_map('intval', (array)($post['store_ids'] ?? [0]))),
            'customer_group_ids' => implode(',', array_map('intval', (array)($post['customer_group_ids'] ?? []))),
            'from_date' => self::date($post['from_date'] ?? ''),
            'to_date' => self::date($post['to_date'] ?? ''),
            'position' => self::oneOf($post['position'] ?? '', Form::POSITIONS, 'top-left'),
            'shape' => self::oneOf($post['shape'] ?? '', Form::SHAPES, 'rectangle'),
            'bg_color' => self::color($post['bg_color'] ?? '', '#d0021b'),
            'text_color' => self::color($post['text_color'] ?? '', '#ffffff'),
            'show_on_listing' => (int)!empty($post['show_on_listing']),
            'show_on_product' => (int)!empty($post['show_on_product']),
            'only_new' => (int)!empty($post['only_new']),
            'only_sale' => (int)!empty($post['only_sale']),
            'stock_status' => self::oneOf($post['stock_status'] ?? '', Form::STOCK, '') ?: null,
            'conditions_serialized' => $this->conditions($post['rule']['conditions'] ?? []),
        ];
        if ($row['name'] === '' || $row['text'] === '') {
            $this->messageManager->addErrorMessage(__('Name and text are required.'));
            return $redirect->setPath('*/*/edit', $id ? ['id' => $id] : []);
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(Indexer::LABEL_TABLE);
        if ($id) {
            $connection->update($table, $row, ['label_id = ?' => $id]);
        } else {
            $connection->insert($table, $row);
            $id = (int)$connection->lastInsertId($table);
        }
        $this->indexer->reindex($id);
        // ponytail: whole caches, a label can touch any product; tag-based clean if this hurts.
        // block_html holds Hyvä's cached product cards, labels included.
        foreach (['full_page', 'block_html'] as $type) {
            $this->cacheTypeList->cleanType($type);
        }

        $this->messageManager->addSuccessMessage(__('The label has been saved.'));
        return $redirect->setPath($this->getRequest()->getParam('back') ? '*/*/edit' : '*/*/', ['id' => $id]);
    }

    /**
     * Same round trip core's catalog rule save does, without saving a rule.
     * Null when the tree is empty, so the label skips the index.
     */
    private function conditions(array $posted): ?string
    {
        $rule = $this->ruleFactory->create();
        $rule->loadPost(['conditions' => $posted]);
        $tree = $rule->getConditions()->asArray();
        return empty($tree['conditions']) ? null : $this->json->serialize($tree);
    }

    private static function oneOf(string $value, array $options, string $default): string
    {
        return isset($options[$value]) ? $value : $default;
    }

    private static function color(string $value, string $default): string
    {
        return preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtolower($value) : $default;
    }

    private static function date(string $value): ?string
    {
        $date = \DateTime::createFromFormat('!Y-m-d', $value);
        return $date ? $date->format('Y-m-d') : null;
    }
}
