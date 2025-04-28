<?php

namespace Puleeno\Rake\WordPress\Traits;

use WP_Error;
use WC_Product_Simple;
use WC_Product_Attribute;
use WC_Data_Exception;
use Ramphor\Rake\Facades\Logger;

trait WooCommerceProcessor
{
    protected $importedId;
    protected $importedNewType;

    protected $appendProductCategories = false;
    protected $appendProductTags = false;

    /**
     * @var \Ramphor\Rake\DataSource\FeedItem
     */
    protected $feedItem;

    /**
     * @var \Ramphor\Rake\Abstracts\Tooth
     */
    protected $tooth;

    /**
     * Import product from feed item
     *
     * @param string $productContent
     * @param string $title
     * @link https://docs.woocommerce.com/wc-apidocs/class-WC_Product.html
     *
     * @return int|null|WP_Error
     */
    public function importProduct($productContent = null, $title = null)
    {
        if (!class_exists(WC_Product_Simple::class)) {
            return new WP_Error('rake_import', 'The WooCommerce product is not registed in your system');
        }

        if (empty($productContent)) {
            if (!empty($this->feedItem->productDesc)) {
                $productContent = (string)$this->feedItem->productDesc;
                $this->feedItem->setProperty(
                    'content',
                    $productContent
                );
            } else {
                $productContent = (string)$this->feedItem->content;
            }
        } else {
            $productContent = (string)$productContent;
            $this->feedItem->setProperty(
                'content',
                $productContent
            );
        }

        $productName = $title;
        if (is_null($productName)) {
            if ($this->feedItem->productName) {
                $productName = $this->feedItem->productName;
                $this->feedItem->setProperty('title', $productName);
            } else {
                $productName = $this->feedItem->title;
            }
        }
        $originalId       = $this->feedItem->originalId;

        $this->importedId = $this->checkIsExists($productName, $originalId, 'product');

        if ($this->importedId > 0) {
            return $this->importedId;
        }

        $product       = $this->createProduct();
        $productPrice  = intval($this->feedItem->productPrice);

        $product->set_name($productName);
        $product->set_description(
            $this->cleanupContentBeforeImport($productContent)
        );
        if ($this->feedItem->productShortDesc || $this->feedItem->productShortDescription) {
            $product->set_short_description(
                $this->feedItem->productShortDesc
                    ? $this->feedItem->productShortDesc
                    : $this->feedItem->productShortDescription
            );
        }
        if ($this->feedItem->slug) {
            $product->set_slug($this->feedItem->slug);
        }
        $product->set_regular_price($productPrice);


        // Sale price
        $productSalePrice  = intval($this->feedItem->productSalePrice);
        if ($productSalePrice > 0) {
            $product->set_sale_price($productSalePrice);
        }

        $this->importedId = $product->save();

        if ($this->importedId > 0) {
            update_post_meta($this->importedId, '_original_id', $originalId);
        }

        return $this->importedId;
    }

    /**
     * Import product category
     *
     * @return array|\WP_Error
     */
    public function importProductCategory($name = null, $description = null, $slug = null)
    {
        $name = empty($name) ? $this->feedItem->productCategoryName : $name;
        $description = empty($description) ? $this->feedItem->productCategoryDesc : $description;
        $term = term_exists($name, 'product_cat');

        $termDesc = $this->cleanupContentBeforeImport($description);

        $this->feedItem->setProperty('content', $termDesc);

        $categoryArgs = array(
            'name' => $name,
            'description' => $this->feedItem->content,
        );

        // Set slug value
        if (!empty($slug)) {
            $categoryArgs['slug'] = $slug;
        } elseif ($this->feedItem->slug) {
            $categoryArgs['slug'] = $this->feedItem->slug;
        }

        if ($term > 0) {
            $term_taxonomy = wp_update_term($term, 'product_cat', $categoryArgs);
        } else {
            $term_taxonomy = wp_insert_term($name, 'product_cat', $categoryArgs);
        }

        if (is_wp_error($term_taxonomy)) {
            return $this->importedId = $term_taxonomy;
        }

        // set product category content to attribute `content` to download images and update content text to new URL.

        $this->importedId      = $term_taxonomy['term_id'];
        $this->importedNewType = 'product_cat';

        return $this->importedId;
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
     *
     * @return \WC_Product
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

        $termIds           = [];
        $parentId          = 0;
        $productCategories = apply_filters(
            "{$this->tooth->getId()}_product_categories",
            $productCategories,
            $this->feedItem,
            $productId
        );

        if (is_array($productCategories)) {
            // Remove the categories with empty names;
            $productCategories = array_filter($productCategories);

            foreach ($productCategories as $category) {
                $category = trim($category);
                $term     = term_exists($category, 'product_cat', $parentId);
                if (!is_null($term)) {
                    $termId    = (int)$term['term_id'];
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

                Logger::debug(sprintf('Insert new product category: "%s"', $category), $categoryArgs);
                $term = wp_insert_term($category, 'product_cat', $categoryArgs);
                if (is_wp_error($term)) {
                    Logger::warning($term->get_error_message(), $categoryArgs);
                    continue;
                }

                $termId    = (int)$term['term_id'];
                $termIds[] = $termId;
                if ($isNested) {
                    $parentId = $termId;
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

    protected function createProductAttribute($name, $taxonomy)
    {
        $args = array(
            'name'         => $name,
            'slug'         => $taxonomy,
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false,
        );
        return wc_create_attribute($args);
    }

    /**
     * Create product attributes for WooCommerce product
     *
     * @param array $productAttributes List product attributes with values
     *
     * @link https://stackoverflow.com/questions/53944532/auto-set-specific-attribute-term-value-to-purchased-products-on-woocommerce
     */
    public function importProductAttributes($productAttributes, $productId = null)
    {
        if (!is_array($productAttributes)) {
            Logger::info(sprintf(
                'The product attributes is invalid to import %s for product #%d',
                var_export($productAttributes, true),
                $productId
            ));
            return;
        }
        if (is_null($productId)) {
            if (empty($this->importedId)) {
                Logger::warning('The post ID is not set value. Please set it before import categories', (array)$this->feedItem);
                return;
            }
            $productId = $this->importedId;
        }
        $product = wc_get_product($productId);
        if (is_null($product)) {
            Logger::warning(sprintf('The product #%d is not exists to import attributes'));
            return;
        }
        $attributes = (array)$product->get_attributes();
        $attributeTerms = array();

        foreach ($productAttributes as $attribute => $attributeValue) {
            $attributeType    = is_array($attributeValue) ? 'select' : 'custom';
            $productAttribute = ($attributeType === 'select') ? sprintf('pa_%s', $attribute) : wc_sanitize_taxonomy_name($attribute);
            $attributeName    = ($attributeType === 'select') ? $attributeValue['name'] : $attribute;
            $attributeValue   = ($attributeType === 'select') ? $attributeValue['value'] : $attributeValue;

            $wcAttribute = isset($attributes[$productAttribute]) ? $attributes[$productAttribute] : new WC_Product_Attribute();


            if ($attributeType === 'select') {
                if (!taxonomy_exists($productAttribute)) {
                    $attributeId = $this->createProductAttribute($attributeName, $productAttribute);
                    $wcAttribute->set_id($attributeId);
                }
                $term = get_term_by('name', $attributeValue, $productAttribute);
                if (is_null($term)) {
                    $term = wp_insert_term($attributeValue, $productAttribute);
                }
                if (is_wp_error($term) || empty($term)) {
                    Logger::warning(sprintf(
                        'The %s attribute(%s) has value %s insert is failed',
                        $attributeName,
                        $productAttribute,
                        $attributeValue
                    ));
                    continue;
                }
                $options = (array)$wcAttribute->get_options();
                $options[] = $term->term_id;

                $wcAttribute->set_options($options);
                $wcAttribute->set_visible(true);
                $wcAttribute->set_variation(false);
                if (!isset($attributes[$productAttribute])) {
                    $wcAttribute->set_position(sizeof($attributes) + 1);
                }
                $attributes[$productAttribute] = $wcAttribute;
            } else { // Custom attributes
                $wcAttribute->set_id(0);
                $wcAttribute->set_name($attributeName);
                $wcAttribute->set_options(explode(
                    constant('WC_DELIMITER'),
                    $attributeValue
                ));
                $wcAttribute->set_visible(true);
                $wcAttribute->set_variation(false);
                if (!isset($attributes[$productAttribute])) {
                    $wcAttribute->set_position(sizeof($attributes) + 1);
                }
                $attributes[$productAttribute] = $wcAttribute;
            }
        }

        $product->set_attributes($attributes);
        $product->save();
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
        $product = wc_get_product($productId);
        if (is_null($product)) {
            Logger::warning(sprintf('The product #%d is not exists to import SKU'));
            return;
        }
        try {
            $product->set_sku($sku);
            $product->save();
        } catch (WC_Data_Exception $e) {
            ob_start();
            debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $errorLogs = ob_get_clean();
            Logger::error(
                sprintf(
                    '%s(SKU: %s - Product #%d)\n%s',
                    $e->getMessage(),
                    $sku,
                    $productId,
                    $errorLogs
                ),
                [
                    'SKU' => $sku,
                    'productID' => $productId
                ]
            );
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

        $product = wc_get_product($productId);
        if (is_null($product)) {
            Logger::warning(sprintf('The product #%d is not exists to import SKU'));
            return;
        }
        $product->set_stock_status($status);
        $product->save();
    }


    public function importPostCategory($title = null, $description = null, $slug = null, $shortDescription = null, $taxonomy = 'category')
    {
        $title = empty($title) ? $title : $this->feedItem->title;
        $slug = empty($slug) ? $slug : $this->feedItem->slug;
        $description = empty($description) ? $description : $this->feedItem->description;
        $taxonomy = empty($taxonomy) ? $taxonomy : $this->feedItem->taxonomy;

        $term = wp_insert_term($title, $taxonomy, array(
            'description' => $description,
            'slug' => $slug,
        ));

        if (is_wp_error($term)) {
            Logger::warning($term->get_error_message(), array(
                'title' => $title,
                'slug' => $slug,
                'description' => $description,
                'taxonomy' => $taxonomy
            ));
            return false;
        }

        if (empty($shortDescription)) {
            update_term_meta(
                $term['term_id'],
                pl_get_post_category_short_description_meta_name(),
                $shortDescription
            );
        }
        return $term['term_id'];
    }
}
