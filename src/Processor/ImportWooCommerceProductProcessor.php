<?php

namespace RamphorRake\Adapter\Processor;

use Rake\Processor\AbstractProcessor;
use Rake\Contracts\Entities\ParsedDataItemInterface;

/**
 * Import WooCommerce Product Processor
 * 
 * Creates or updates WooCommerce products from parsed data
 */
class ImportWooCommerceProductProcessor extends AbstractProcessor
{
    /**
     * Process data item and import as WooCommerce product
     * 
     * @param ParsedDataItemInterface $item Extracted data item
     * @return ParsedDataItemInterface
     */
    public function process(ParsedDataItemInterface $item): ParsedDataItemInterface
    {
        // Skip if already null item
        if ($item->isNull()) {
            return $item;
        }

        // Check if WooCommerce is active
        if (!function_exists('wc_create_product')) {
            $this->logError('WooCommerce is not active');
            return $this->createNullItem('WooCommerce is not active');
        }

        try {
            // Get product data from item
            $productName = $item->get('product_name') ?? $item->get('name') ?? $item->get('title') ?? '';
            $productSku = $item->get('sku') ?? $item->get('item_number') ?? '';
            $productPrice = $item->get('price') ?? '0.00';
            $productDescription = $item->get('description') ?? $item->get('product_description') ?? '';
            $productShortDescription = $item->get('short_description') ?? '';
            $productUrl = $item->get('url') ?? '';
            $productImages = $item->get('product_images') ?? [];
            $categoryIds = $this->getCategoryIds($item);
            $productStatus = $this->getConfig('product_status', 'draft');
            $manageStock = $this->getConfig('manage_stock', false);
            $stockQuantity = $item->get('stock_quantity') ?? null;

            // Validate required fields
            if (empty($productName)) {
                return $this->createNullItem('Product name is required');
            }

            // Check if product already exists
            $existingProductId = $this->findExistingProduct($productUrl, $productSku);

            // Prepare product data
            $productData = [
                'name' => $productName,
                'type' => 'simple',
                'regular_price' => $productPrice,
                'description' => $productDescription,
                'short_description' => $productShortDescription,
                'status' => $productStatus,
                'manage_stock' => $manageStock,
            ];

            // Set SKU if available
            if (!empty($productSku)) {
                $productData['sku'] = $productSku;
            }

            // Set stock quantity if managing stock
            if ($manageStock && $stockQuantity !== null) {
                $productData['stock_quantity'] = (int) $stockQuantity;
            }

            if ($existingProductId) {
                // Update existing product
                $product = wc_get_product($existingProductId);
                if (!$product) {
                    return $this->createNullItem('Product not found for update');
                }

                foreach ($productData as $key => $value) {
                    $setter = 'set_' . $key;
                    if (method_exists($product, $setter)) {
                        $product->$setter($value);
                    }
                }

                $product->save();
                $productId = $existingProductId;
                $this->log('Product updated', ['product_id' => $productId, 'name' => $productName]);
            } else {
                // Create new product
                $productId = wc_create_product($productData);

                if (is_wp_error($productId)) {
                    $this->logError('Failed to create product', ['error' => $productId->get_error_message()]);
                    return $this->createNullItem('Failed to create product: ' . $productId->get_error_message());
                }

                $this->log('Product created', ['product_id' => $productId, 'name' => $productName]);
            }

            $product = wc_get_product($productId);

            // Set categories
            if (!empty($categoryIds)) {
                $product->set_category_ids($categoryIds);
                $product->save();
            }

            // Set images
            if (!empty($productImages)) {
                $this->setProductImages($product, $productImages);
            }

            // Store original URL as post meta
            if (!empty($productUrl)) {
                update_post_meta($productId, '_original_product_url', $productUrl);
            }

            // Store product ID from source
            $sourceProductId = $item->get('product_id') ?? '';
            if (!empty($sourceProductId)) {
                update_post_meta($productId, '_original_product_id', $sourceProductId);
            }

            // Handle quantity-based pricing
            $quantityPrices = $item->get('quantity_prices') ?? [];
            if (!empty($quantityPrices)) {
                update_post_meta($productId, '_quantity_prices', $quantityPrices);
            }

            // Return updated item with product info
            return $item
                ->set('woocommerce_product_id', $productId)
                ->set('product_imported', true);

        } catch (\Exception $e) {
            $this->logError('Processing error', ['error' => $e->getMessage()]);
            return $this->createNullItem('Error: ' . $e->getMessage());
        }
    }

    /**
     * Get category IDs from item
     * 
     * @param ParsedDataItemInterface $item
     * @return array
     */
    private function getCategoryIds(ParsedDataItemInterface $item): array
    {
        $categoryIds = [];

        // Get from woocommerce_category_id if set by previous processor
        $categoryId = $item->get('woocommerce_category_id');
        if (!empty($categoryId)) {
            $categoryIds[] = (int) $categoryId;
        }

        // Also check for category_url to find existing category
        $categoryUrl = $item->get('category_url') ?? '';
        if (!empty($categoryUrl)) {
            $termId = $this->findCategoryByUrl($categoryUrl);
            if ($termId && !in_array($termId, $categoryIds)) {
                $categoryIds[] = $termId;
            }
        }

        return $categoryIds;
    }

    /**
     * Find category by original URL
     * 
     * @param string $url Category URL
     * @return int|null Term ID if found
     */
    private function findCategoryByUrl(string $url): ?int
    {
        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key' => '_original_category_url',
                    'value' => $url,
                    'compare' => '='
                ]
            ]
        ]);

        if (!is_wp_error($terms) && !empty($terms)) {
            return $terms[0]->term_id;
        }

        return null;
    }

    /**
     * Find existing product by URL or SKU
     * 
     * @param string $url Product URL
     * @param string $sku Product SKU
     * @return int|null Product ID if found
     */
    private function findExistingProduct(string $url, string $sku): ?int
    {
        // First, try to find by URL
        if (!empty($url)) {
            $products = get_posts([
                'post_type' => 'product',
                'posts_per_page' => 1,
                'meta_query' => [
                    [
                        'key' => '_original_product_url',
                        'value' => $url,
                        'compare' => '='
                    ]
                ]
            ]);

            if (!empty($products)) {
                return $products[0]->ID;
            }
        }

        // If not found by URL, try by SKU
        if (!empty($sku)) {
            global $wpdb;
    
