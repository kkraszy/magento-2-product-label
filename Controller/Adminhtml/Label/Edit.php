<?php
/**
 * New and edit in one: no `id` is a new label. The form block reads the row itself.
 */

declare(strict_types=1);

namespace Kkkonrad\ProductLabels\Controller\Adminhtml\Label;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;

class Edit extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Kkkonrad_ProductLabels::labels';

    public function execute(): ResultInterface
    {
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Kkkonrad_ProductLabels::labels');
        $page->getConfig()->getTitle()->prepend(
            $this->getRequest()->getParam('id') ? __('Edit label') : __('New label')
        );
        return $page;
    }
}
