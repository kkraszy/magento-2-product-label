<?php
declare(strict_types=1);

namespace Kkkonrad\ProductLabels\Controller\Adminhtml\Label;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Kkkonrad_ProductLabels::labels';

    public function execute(): ResultInterface
    {
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Kkkonrad_ProductLabels::labels');
        $page->getConfig()->getTitle()->prepend(__('Product labels'));
        return $page;
    }
}
