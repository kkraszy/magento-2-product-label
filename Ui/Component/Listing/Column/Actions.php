<?php
declare(strict_types=1);

namespace Kkkonrad\ProductLabels\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

class Actions extends Column
{
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $url,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    public function prepareDataSource(array $dataSource): array
    {
        foreach ($dataSource['data']['items'] ?? [] as &$item) {
            $item[$this->getData('name')]['edit'] = [
                'href' => $this->url->getUrl('kkkonrad_productlabels/label/edit', ['id' => $item['label_id']]),
                'label' => __('Edit'),
            ];
        }
        return $dataSource;
    }
}
