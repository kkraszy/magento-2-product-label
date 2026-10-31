# Kkkonrad_ProductLabels

Product labels ("Nowość", "-20%", "Ostatnie sztuki"...) over product images on
listings and the product page. Modelled on Amasty Product Labels, but smaller:
text labels in four shapes, nine positions, rule conditions, scheduling.

Works under every Hyvä port here with no template overrides. Listings also
work under Luma. The PDP overlay is Hyvä only (it uses `gallery.additional`).

## Install

```bash
bin/magento module:enable Kkkonrad_ProductLabels
bin/magento setup:upgrade
bin/magento cache:flush
```

Cron must run for the nightly reindex (see below).

## Admin

**Catalog › Inventory › Product labels** (ACL `Kkkonrad_ProductLabels::labels`).
Building conditions also needs **Marketing › Catalog Price Rule** access, because
"add condition" calls core's own endpoint.

| Field | Meaning |
|---|---|
| Text | Free text plus `{SAVE_PERCENT}`, `{SAVE_AMOUNT}`, `{PRICE}`, `{SKU}`. If the text uses a discount variable and the product is at full price, the label is skipped |
| Priority / Hide if a higher-priority label is shown | Lower number first. With the flag set, the label is skipped once any label above it has shown |
| Position, Shape, colours | 3×3 grid; rectangle / square corners / pill / circle; native colour pickers, stored as `#rrggbb` (anything else falls back to the default) |
| Show on listings / product page | Each area separately |
| Store views, Customer groups, From / To | No groups selected = all groups. Dates inclusive, store timezone |
| Only new / Only discounted / Stock | Checked at render time: "Set Product as New From/To", final price < regular price, salable or not |
| Conditions | Core's catalog price rule condition tree (attributes, category, SKU, attribute set...). Empty = all products |

## How it works

| Where | What |
|---|---|
| `Plugin/ProductImage.php` | Listing: `ImageFactory::create` hands over the product, `Image::toHtml` appends the labels. Skips cart, wishlist, compare and checkout images by image id |
| `view/frontend/layout/catalog_product_view.xml` | PDP: block in Hyvä's `gallery.additional` |
| `Model/Labels.php` | Labels for one product: store/date/group filter, live checks, variables. One label query per request |
| `Model/Indexer.php` | Products matching each label's conditions in `kkkonrad_product_label_product`, via a throwaway `CatalogRule\Model\Rule`. Refreshed on label save, on product save (`catalog_product_save_after`), and nightly at 03:15 (cron `kkkonrad_productlabels_reindex`) for imports and mass updates |
| `Block/Adminhtml/Label/Form.php` | The edit form, a classic widget form so the rule tree works |
| `view/frontend/web/css/labels.css` | `kk-` classes, `em` sizes (Luma root 10px, Hyvä 16px) |

**Theme hook.** Top-left labels start at `--kk-labels-top-left` (default `.8em`).
A theme whose cards keep their own badges in that corner sets it to where those
badges end. The release, impact and impulse ports do this in
`web/tailwind/components/badge.css` (one or two badge rows). It is a variable
and not an override because the module CSS is unlayered and would beat any
rule in a Tailwind layer.

A configurable gets the label when it or **any of its variants** matches the
conditions. Saving or deleting a label cleans `full_page` and `block_html`
(Hyvä caches product cards for an hour).

Test: `vendor/bin/phpunit app/code/Kkkonrad/ProductLabels/Test/Unit`

## Sample configuration on staging

| # | Label | Shows on | Where | Look |
|---|---|---|---|---|
| 3 | **Nowość** | "Set Product as New From" set: MJ08, WJ04, MH09, WSH03, WSH06, WSH09 (from 2026-10-01, via mass attribute update) | listing + PDP | top-left, green pill |
| 9 | **Bestseller** | condition: SKU is one of MJ01, WJ12, MH07, 24-MB01, WSH04, WSH10 | listing + PDP | top-left, black |
| 10 | **Darmowa dostawa** | condition: price ≥ 75 (configurables through their variants) | listing + PDP | top-left, beige, square corners |
| 4 | **-{SAVE_PERCENT}%** | only discounted products | PDP only (theme cards have their own sale badge) | top-right, red circle |
| 11 | **Cena hurtowa** | every product, Wholesale customer group only | PDP only | bottom-left, blue |

## Not included (compared with Amasty)

- Image labels (uploaded PNG/SVG). Text and shape only.
- Separate text and look for listing vs product page. One label, two switches.
- Per-label size slider and custom CSS; `{ATTR:code}`, `{NEW_FOR}`, countdown variables.
- Store-view-specific attribute values in conditions: matching uses each website's default store view.
- A label whose dates start or end today stays in cached pages until the cache expires.
