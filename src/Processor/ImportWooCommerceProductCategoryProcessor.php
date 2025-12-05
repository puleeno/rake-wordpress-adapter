<?php

namespace RamphorRake\Adapter\Processor;

use Rake\Processor\AbstractProcessor;
use Rake\Contracts\Entities\ParsedDataItemInterface;

/**
 * Import WooCommerce Product Category Processor
 * 
 * Creates or updates WooCommerce product categories from parsed data
 */
class ImportWooCommerceProductCategoryProcessor extends AbstractProcessor
{
    /**
     * Process data item and import as WooCommerce product category
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
        if (!class_exists('WooCommerce')) {
            $this->logError('WooCommerce is not active');
            return $this->createNullItem('WooCommerce is not active');
        }

        try {
            // Get category data from item
            $categoryName = $item->get('category_name') ?? $item->get('name') ?? '';
            $categorySlug = $item->get('category_slug') ?? $item->get('slug') ?? '';
            $categoryDescription = $item->get('category_description') ?? $item->get('description') ?? '';
            $parentCategoryId = $item->get('parent_category_id') ?? 0;
            $categoryUrl = $item->get('url') ?? $item->get('category_url') ?? '';
            $categoryId = $item->get('category_id') ?? '';

            // Validate required fields
            if (empty($categoryName)) {
                return $this->createNullItem('Category name is required');
            }

            // Generate slug if not provided
            if (empty($categorySlug)) {
                $categorySlug = sanitize_title($categoryName);
            }

            // Check if category already exists by URL or slug
            $existingTermId = $this->findExistingCategory($categoryUrl, $categorySlug);

            // Prepare term data
            $termData = [
                'description' => $categoryDescription,
                'parent' => (int) $parentCategoryId,
            ];

            if ($existingTermId) {
                // Update existing category
                $result = wp_update_term($existingTermId, 'product_cat', $termData);
                
                if (is_wp_error($result)) {
                    $this->logError('Failed to update category', ['error' => $result->get_error_message()]);
                    return $this->createNullItem('Failed to update category: ' . $result->get_error_message());
                }

                $termId = $result['term_id'];
                $this->log('Category updated', ['term_id' => $termId, 'name' => $categoryName]);
            } else {
                // Create new category
                $result = wp_insert_term($categoryName, 'product_cat', $termData);
                
                if (is_wp_error($result)) {
                    // If term exists with different slug, get it
                    if ($result->get_error_code() === 'term_exists') {
                        $termId = $result->get_error_data()['term_id'];
                        $this->log('Category already exists', ['term_id' => $termId]);
                    } else {
                        $this->logError('Failed to create category', ['error' => $result->get_error_message()]);
                        return $this->createNullItem('Failed to create category: ' . $result->get_error_message());
                    }
                } else {
                    $termId = $result['term_id'];
                    $this->log('Category created', ['term_id' => $termId, 'name' => $categoryName]);
                }
            }

            // Update slug if provided
            if (!empty($categorySlug) && $categorySlug !== get_term_field('slug', $termId, 'product_cat')) {
                wp_update_term($termId, 'product_cat', ['slug' => $categorySlug]);
            }

            // Store original URL as term meta for reference
            if (!empty($categoryUrl)) {
                update_term_meta($termId, '_original_category_url', $categoryUrl);
            }

            // Store original category ID if provided
            if (!empty($categoryId)) {
                update_term_meta($termId, '_original_category_id', $categoryId);
            }

            // Return updated item with category info
            return $item
                ->set('woocommerce_category_id', $termId)
                ->set('woocommerce_category_slug', get_term_field('slug', $termId, 'product_cat'))
                ->set('category_imported', true);

        } catch (\Exception $e) {
            $this->logError('Processing error', ['error' => $e->getMessage()]);
            return $this->createNullItem('Error: ' . $e->getMessage());
        }
    }

    /**
     * Find existing category by URL or slug
     * 
     * @param string $url Category URL
     * @param string $slug Category slug
     * @return int|null Term ID if found, null otherwise
     */
    private function findExistingCategory(string $url, string $slug): ?int
    {
        // First, try to find by URL (stored in term meta)
        if (!empty($url)) {
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
        }

        // If not found by URL, try by slug
        if (!empty($slug)) {
            $term = get_term_by('slug', $slug, 'product_cat');
            if ($term) {
                return $term->term_id;
            }
        }

        return null;
    }
}
