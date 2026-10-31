<?php
/**
 * The edit form, a classic widget form so core's rule-condition tree renders
 * and posts as it does on Marketing > Catalog Price Rule. "Add condition"
 * calls core's catalog_rule/promo_catalog/newConditionHtml, so the admin
 * also needs that page's ACL.
 *
 * The container around it (buttons, `#edit_form`) is the stock
 * Widget\Form\Container, wired in the edit layout.
 */

declare(strict_types=1);

namespace Kkkonrad\ProductLabels\Block\Adminhtml\Label;

use Kkkonrad\ProductLabels\Model\Indexer;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget\Form\Generic;
use Magento\Backend\Block\Widget\Form\Renderer\Fieldset as FieldsetRenderer;
use Magento\CatalogRule\Model\RuleFactory;
use Magento\Config\Model\Config\Source\Yesno;
use Magento\Customer\Model\Customer\Source\GroupSourceInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\Registry;
use Magento\Rule\Block\Conditions;
use Magento\Rule\Model\Condition\AbstractCondition;
use Magento\Store\Model\System\Store;

class Form extends Generic
{
    public const POSITIONS = [
        'top-left' => 'Top left', 'top-center' => 'Top center', 'top-right' => 'Top right',
        'middle-left' => 'Middle left', 'middle-center' => 'Middle center', 'middle-right' => 'Middle right',
        'bottom-left' => 'Bottom left', 'bottom-center' => 'Bottom center', 'bottom-right' => 'Bottom right',
    ];
    public const SHAPES = ['rectangle' => 'Rectangle', 'square' => 'Square corners', 'pill' => 'Pill', 'circle' => 'Circle'];
    public const STOCK = ['' => 'Any', 'in' => 'In stock only', 'out' => 'Out of stock only'];

    private const FORM_NAME = 'kk_label_form';

    public function __construct(
        Context $context,
        Registry $registry,
        FormFactory $formFactory,
        private readonly ResourceConnection $resource,
        private readonly Store $systemStore,
        private readonly GroupSourceInterface $customerGroups,
        private readonly Yesno $yesno,
        private readonly RuleFactory $ruleFactory,
        private readonly Conditions $conditions,
        array $data = []
    ) {
        parent::__construct($context, $registry, $formFactory, $data);
    }

    protected function _prepareForm()
    {
        $id = (int)$this->getRequest()->getParam('id');
        $label = $id ? $this->load($id) : [];

        $form = $this->_formFactory->create(['data' => [
            'id' => 'edit_form',
            'action' => $this->getUrl('*/*/save'),
            'method' => 'post',
            'use_container' => true,
        ]]);
        $form->setHtmlIdPrefix('label_');
        $yesno = $this->yesno->toOptionArray();

        $general = $form->addFieldset('general', ['legend' => __('Label')]);
        if ($label) {
            $general->addField('label_id', 'hidden', ['name' => 'label_id']);
        }
        $general->addField('name', 'text', [
            'name' => 'name', 'label' => __('Name'), 'required' => true,
            'note' => __('Admin only.'),
        ]);
        $general->addField('is_active', 'select', ['name' => 'is_active', 'label' => __('Active'), 'values' => $yesno]);
        $general->addField('text', 'text', [
            'name' => 'text', 'label' => __('Text'), 'required' => true,
            'note' => __('Variables: {SAVE_PERCENT}, {SAVE_AMOUNT}, {PRICE}, {SKU}. A label whose discount variable does not apply (full price) is not shown.'),
        ]);
        $general->addField('priority', 'text', [
            'name' => 'priority', 'label' => __('Priority'), 'class' => 'validate-number',
            'note' => __('Lower shows first.'),
        ]);
        $general->addField('hide_if_higher', 'select', [
            'name' => 'hide_if_higher', 'label' => __('Hide if a higher-priority label is shown'), 'values' => $yesno,
        ]);

        $look = $form->addFieldset('look', ['legend' => __('Appearance')]);
        $look->addField('position', 'select', ['name' => 'position', 'label' => __('Position'), 'values' => self::options(self::POSITIONS)]);
        $look->addField('shape', 'select', ['name' => 'shape', 'label' => __('Shape'), 'values' => self::options(self::SHAPES)]);
        // Native colour pickers: the text element prints whatever type it is given
        $look->addField('bg_color', 'text', ['name' => 'bg_color', 'label' => __('Background')])->setType('color');
        $look->addField('text_color', 'text', ['name' => 'text_color', 'label' => __('Text colour')])->setType('color');
        $look->addField('show_on_listing', 'select', ['name' => 'show_on_listing', 'label' => __('Show on product listings'), 'values' => $yesno]);
        $look->addField('show_on_product', 'select', ['name' => 'show_on_product', 'label' => __('Show on product page'), 'values' => $yesno]);

        $where = $form->addFieldset('where', ['legend' => __('Where and when')]);
        $where->addField('store_ids', 'multiselect', [
            'name' => 'store_ids[]', 'label' => __('Store views'), 'required' => true,
            'values' => $this->systemStore->getStoreValuesForForm(false, true),
        ]);
        $where->addField('customer_group_ids', 'multiselect', [
            'name' => 'customer_group_ids[]', 'label' => __('Customer groups'),
            'values' => $this->customerGroups->toOptionArray(),
            'note' => __('None selected = all groups.'),
        ]);
        $where->addField('from_date', 'date', ['name' => 'from_date', 'label' => __('From'), 'date_format' => 'yyyy-MM-dd']);
        $where->addField('to_date', 'date', ['name' => 'to_date', 'label' => __('To'), 'date_format' => 'yyyy-MM-dd']);

        $which = $form->addFieldset('which', ['legend' => __('Products')]);
        $which->addField('only_new', 'select', [
            'name' => 'only_new', 'label' => __('Only products set as new'), 'values' => $yesno,
            'note' => __('Today within the product\'s "Set Product as New From / To".'),
        ]);
        $which->addField('only_sale', 'select', [
            'name' => 'only_sale', 'label' => __('Only discounted products'), 'values' => $yesno,
            'note' => __('Final price below the regular price: special price, catalog price rule or tier price for one.'),
        ]);
        $which->addField('stock_status', 'select', ['name' => 'stock_status', 'label' => __('Stock'), 'values' => self::options(self::STOCK)]);

        $this->addConditions($form, $label['conditions_serialized'] ?? null);

        $form->setValues($label + [
            'is_active' => 1, 'priority' => 0, 'position' => 'top-left', 'shape' => 'rectangle',
            'bg_color' => '#d0021b', 'text_color' => '#ffffff', 'show_on_listing' => 1, 'show_on_product' => 1,
            'store_ids' => '0',
        ]);
        $this->setForm($form);
        return parent::_prepareForm();
    }

    /**
     * Core's own rule tree, as CatalogRule's Edit\Tab\Conditions::addTabToForm builds it.
     */
    private function addConditions(\Magento\Framework\Data\Form $form, ?string $serialized): void
    {
        $rule = $this->ruleFactory->create();
        if ($serialized) {
            $rule->setConditionsSerialized($serialized);
        }
        $fieldsetId = $rule->getConditionsFieldSetId(self::FORM_NAME);

        $renderer = $this->getLayout()->createBlock(FieldsetRenderer::class)
            ->setTemplate('Magento_CatalogRule::promo/fieldset.phtml')
            ->setNewChildUrl($this->getUrl(
                'catalog_rule/promo_catalog/newConditionHtml/form/' . $fieldsetId,
                ['form_namespace' => self::FORM_NAME]
            ))
            ->setFieldSetId($fieldsetId);

        $form->addFieldset('conditions_fieldset', [
            'legend' => __('Conditions (leave empty to match all products)'),
        ])->setRenderer($renderer)
            ->addField('conditions', 'text', ['name' => 'conditions', 'label' => __('Conditions')])
            ->setRule($rule)
            ->setRenderer($this->conditions);

        $this->setJsFormObject($rule->getConditions(), $fieldsetId);
    }

    private function setJsFormObject(AbstractCondition $condition, string $fieldsetId): void
    {
        $condition->setFormName(self::FORM_NAME)->setJsFormObject($fieldsetId);
        foreach ($condition->getConditions() ?: [] as $child) {
            $this->setJsFormObject($child, $fieldsetId);
        }
    }

    private function load(int $id): array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->resource->getTableName(Indexer::LABEL_TABLE))->where('label_id = ?', $id)
        );
        if (!$row) {
            return [];
        }
        $row['store_ids'] = explode(',', $row['store_ids']);
        $row['customer_group_ids'] = array_filter(explode(',', $row['customer_group_ids']), 'strlen');
        return $row;
    }

    private static function options(array $map): array
    {
        $options = [];
        foreach ($map as $value => $label) {
            $options[] = ['value' => $value, 'label' => __($label)];
        }
        return $options;
    }
}
