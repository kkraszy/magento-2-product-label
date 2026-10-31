<?php
declare(strict_types=1);

namespace Kkkonrad\ProductLabels\Controller\Adminhtml\Label;

use Kkkonrad\ProductLabels\Model\Indexer;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Controller\ResultInterface;

class Delete extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Kkkonrad_ProductLabels::labels';

    public function __construct(
        Context $context,
        private readonly ResourceConnection $resource,
        private readonly TypeListInterface $cacheTypeList
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        // The index rows go with it (foreign key cascade)
        $this->resource->getConnection()->delete(
            $this->resource->getTableName(Indexer::LABEL_TABLE),
            ['label_id = ?' => (int)$this->getRequest()->getParam('id')]
        );
        foreach (['full_page', 'block_html'] as $type) {
            $this->cacheTypeList->cleanType($type);
        }
        $this->messageManager->addSuccessMessage(__('The label has been deleted.'));
        return $this->resultRedirectFactory->create()->setPath('*/*/');
    }
}
