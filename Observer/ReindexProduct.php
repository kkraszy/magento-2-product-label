<?php

declare(strict_types=1);

namespace Kkkonrad\ProductLabels\Observer;

use Kkkonrad\ProductLabels\Model\Indexer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class ReindexProduct implements ObserverInterface
{
    public function __construct(private readonly Indexer $indexer)
    {
    }

    public function execute(Observer $observer): void
    {
        $this->indexer->reindexProduct($observer->getEvent()->getProduct());
    }
}
