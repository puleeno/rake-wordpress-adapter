<?php
namespace Puleeno\Rake\WordPress\Traits;

use WC_Product;
use WC_Product_Simple;
use Ramphor\Rake\Facades\Logger;

trait WooCommerceProcessor
{
    protected $importedId;
    protected $appendProductCategories = false;
    protected $appendProductTags = false;

    /**
     * Import product from feed item
     *
     * @param string $productContent
     * @param string $title
     * @link https://docs.woocommerce.com/wc-apidocs/class-WC_Product.html
     * @return void
     */
    public function importProduct($productContent = null, $title = null)
    {
        if (!class_exists(WC_Product_Simple::class)) {
            return new WP_Error('rake_import', 'The WooCommerce product is not registed in your system');
        }
        if (is_null($productContent)) {
            $productContent = (string)$this->feedItem->content;
        } else {
            $productContent = (string)$productContent;
            $this->feedItem->setProperty(
                'content',
                $productContent
            );
        }

        $productName      = is_null($title) ? $this->feedItem->title : $title;
        $originalId       = $this->feedItem->getMeta('original_id', null);
        $this->importedId = $this->checkIsExists($productName, $originalId, 'product');

        if ($this->importedId > 0) {
            return $this->importedId;
        }

        $product       = $this->createProduct();
        $productPrice  = $this->feedItem->getMeta('product_price', 0);

        $product->set_name($productName);
        $product->set_description(
            $this->cleanupContentBeforeImport($productContent)
        );
        $product->set_regular_price($productPrice);

        $this->importedId = $product->save();

        if ($this->importedId > 0) {
            update_post_meta($this->importedId, '_original_id', $originalId);
        }
        return $this->importedId;
    }

    /**
     * Create product attributes for WooCommerce product
     *
     * @param array $productAttributes List product attributes with values
     */
    public function importAttributes($productAttributes, $productId = null)
    {
        if (is_null($productId)) {
            if (empty($this->importedId)) {
                Logger::warning('The post ID is not set value. Please set it before import categories', (array)$this->feedItem);
                return;
            }
            $productId = $this->importedId;
        }
        $product->set_attributes($productAttributes);
    }

    /**
     * With attributes and categories set up and stock management configured, we can begin adding products. When adding a product, the first thing to decide is what type of product it is.
     *
     * Simple – covers the vast majority of any products you may sell. Simple products are shipped and have no options.
     * Grouped – a collection of related products that can be purchased individually and only consist of simple products.
     * Virtual – one that doesn’t require shipping. For example, a service.
     * Downloadable – activates additional fields where you can provide a downloadable file.
     * External or Affiliate – one that you list and describe on your website but is sold elsewhere.
     * Variable – a product with variations, each of which may have a different SKU, price, stock option, etc.
     * Other types are often added by extensions.
     *
     * @param int    $productId    The WooCommerce product ID
     * @param string $productType  The WooCommerce product type
     * @link https://docs.woocommerce.com/document/managing-products/#section-4
     * @link https://github.com/woocommerce/woocommerce/wiki/Product-Data-Schema
     * @return void
     */
    public function createProduct($productType = null)
    {
        return new WC_Product_Simple();
    }

    public function importProductCategories($productCategories, $isNested = false, $productId)
    {
        if (is_null($productId)) {
            if (empty($this->importedId)) {
                Logger::warning('The post ID is not set value. Please set it before import categories', (array)$this->feedItem);
                return;
            }
            $productId = $this->importedId;
        }

        $termIds  = [];
        $parentId = 0;
        if (is_array($categories)) {
            foreach ($categories as $category) {
                $category = trim($category);
                $termId   = term_exists($category, 'product_cat', $parentId);
                if ($termId> 0) {
                    $termIds[] = $termId;
                    if ($isNested) {
                        if ($parentId > 0) {
                            if ($parentId > 0) {
                                wp_update_term($termId, 'product_cat', [
                                    'parent' => $parentId
                                ]);
                            }
                        }
                        $parentId = $termId;
                    }
                    continue;
                }

                $categoryArgs = [];
                if ($isNested && $parentId > 0) {
                    $categoryArgs['parent'] = $parentId;
                }

                $term = wp_insert_term($category, 'product_cat', $categoryArgs);
                if (is_wp_error($termId)) {
                    continue;
                }
                $termIds[] = $term['term_id'];
                if ($isNested) {
                    $parentId = $term['term_id'];
                }
            }
        }

        return wp_set_object_terms($productId, $termIds, 'product_cat', $this->appendProductCategories);
    }

    public function importProductTags($productTags, $productId = null)
    {
        if (is_null($productId)) {
            if (empty($this->importedId)) {
                Logger::warning('The post ID is not set value. Please set it before import categories', (array)$this->feedItem);
                return;
            }
            $productId = $this->importedId;
        }
        return wp_set_object_terms($productId, $productTags, 'product_tag', $this->appendProductTags);
    }

    public function importProductSku($sku, $productId = null)
    {
        if (is_null($productId)) {
            if (empty($this->importedId)) {
                Logger::warning('The post ID is not set value. Please set it before import categories', (array)$this->feedItem);
                return;
            }
            $productId = $this->importedId;
        }
    }

    public function importStockStatus($status, $productId = null)
    {
        if (is_null($productId)) {
            if (empty($this->importedId)) {
                Logger::warning('The post ID is not set value. Please set it before import categories', (array)$this->feedItem);
                return;
            }
            $productId = $this->importedId;
        }
    }
}
