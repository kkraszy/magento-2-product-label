<?php
/**
 * Labels on every product image a theme renders through core's image block:
 * category grid and list, widgets, related / up-sell sliders. Hyvä and Luma
 * alike, no template overrides.
 *
 * The labels are absolutely positioned against the image's nearest
 * positioned ancestor, which every listing card here has
 * (`.product-card__media`, Luma's `.product-image-container`).
 */

declare(strict_types=1);

namespace Kkkonrad\ProductLabels\Plugin;

use Kkkonrad\ProductLabels\Model\Labels;
use Magento\Catalog\Block\Product\Image;
use Magento\Catalog\Block\Product\ImageFactory;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\Template;

class ProductImage
{
    /** Images that are not a product card: cart rows, sidebars, checkout summary */
    private const SKIP = '/cart|wishlist|compare|thumbnail|checkout|order/';

    public function __construct(private readonly Labels $labels)
    {
    }

    public function afterCreate(ImageFactory $subject, Image $result, Product $product, string $imageId): Image
    {
        if (!preg_match(self::SKIP, $imageId)) {
            $result->setData('kk_labels_product', $product);
        }
        return $result;
    }

    public function afterToHtml(Image $subject, string $html): string
    {
        $product = $subject->getData('kk_labels_product');
        $labels = $product && $html !== '' ? $this->labels->forProduct($product, 'listing') : [];
        if (!$labels) {
            return $html;
        }
        return $html . $subject->getLayout()->createBlock(Template::class)
            ->setTemplate('Kkkonrad_ProductLabels::labels.phtml')
            ->setData(['labels' => $labels, 'label_area' => 'listing'])
            ->toHtml();
    }
}
