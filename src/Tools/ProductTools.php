<?php
/**
 * OpenCart Universal MCP Server
 * Compatible with PHP 5.4 - 8.4+
 *
 * @author    Anton Barinov <https://github.com/Anton-Barinov>
 * @license   MIT
 */

namespace OpenCartMcp\Tools;

use OpenCartMcp\Core\Database;
use OpenCartMcp\Core\SchemaAdapter;
use OpenCartMcp\Core\SecurityPolicy;

class ProductTools
{
    /** @var Database */
    private $db;

    /** @var SchemaAdapter */
    private $schema;

    /** @var SecurityPolicy */
    private $security;

    /**
     * @param Database $db
     * @param SchemaAdapter $schema
     * @param SecurityPolicy $security
     */
    public function __construct(Database $db, SchemaAdapter $schema, SecurityPolicy $security)
    {
        $this->db = $db;
        $this->schema = $schema;
        $this->security = $security;
    }

    /**
     * List products with flexible filtering and pagination
     *
     * @param array $args
     * @return array
     */
    public function listProducts(array $args)
    {
        $langId = $this->schema->getDefaultLanguageId();
        $limit = isset($args['limit']) ? min(100, max(1, (int)$args['limit'])) : 20;
        $offset = isset($args['offset']) ? max(0, (int)$args['offset']) : 0;

        $where = ["pd.language_id = " . (int)$langId];
        $params = [];

        if (isset($args['category_id']) && (int)$args['category_id'] > 0) {
            $catId = (int)$args['category_id'];
            $where[] = "p.product_id IN (SELECT product_id FROM {prefix}product_to_category WHERE category_id = {$catId})";
        }

        if (!empty($args['filter_name'])) {
            $where[] = "(pd.name LIKE :fname OR p.model LIKE :fmodel OR p.sku LIKE :fsku)";
            $params['fname'] = '%' . $args['filter_name'] . '%';
            $params['fmodel'] = '%' . $args['filter_name'] . '%';
            $params['fsku'] = '%' . $args['filter_name'] . '%';
        }

        if (isset($args['filter_status']) && $args['filter_status'] !== '') {
            $where[] = "p.status = " . (int)$args['filter_status'];
        }

        $whereClause = implode(" AND ", $where);
        $sql = "SELECT p.product_id, p.model, p.sku, p.price, p.quantity, p.status, p.image, pd.name
                FROM {prefix}product p
                LEFT JOIN {prefix}product_description pd ON (p.product_id = pd.product_id)
                WHERE {$whereClause}
                ORDER BY p.product_id DESC
                LIMIT {$offset}, {$limit}";

        $rows = $this->db->query($sql, $params);

        // Attach SEO Keyword and formatted price
        foreach ($rows as &$row) {
            $row['product_id'] = (int)$row['product_id'];
            $row['quantity'] = (int)$row['quantity'];
            $row['price'] = (float)$row['price'];
            $row['formatted_price'] = $this->schema->formatCurrency($row['price']);
            $row['main_category_id'] = $this->schema->getMainCategoryId($row['product_id']);
            $row['status'] = (int)$row['status'] === 1 ? 'Enabled' : 'Disabled';
            $row['seo_url'] = $this->schema->getSeoKeyword('product_id=' . $row['product_id']);
        }

        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM {prefix}product p
                     LEFT JOIN {prefix}product_description pd ON (p.product_id = pd.product_id)
                     WHERE {$whereClause}";
        $total = (int)$this->db->queryScalar($countSql, $params);

        return [
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'products' => $rows,
        ];
    }

    /**
     * Get comprehensive product details by product_id
     *
     * @param array $args
     * @return array
     * @throws \RuntimeException
     */
    public function getProduct(array $args)
    {
        if (empty($args['product_id'])) {
            throw new \RuntimeException("Missing required parameter: product_id");
        }

        $productId = (int)$args['product_id'];
        $langId = $this->schema->getDefaultLanguageId();

        $sql = "SELECT p.*, pd.name, pd.description, pd.tag, pd.meta_title, pd.meta_description, pd.meta_keyword,
                       m.name AS manufacturer
                FROM {prefix}product p
                LEFT JOIN {prefix}product_description pd ON (p.product_id = pd.product_id AND pd.language_id = :lang)
                LEFT JOIN {prefix}manufacturer m ON (p.manufacturer_id = m.manufacturer_id)
                WHERE p.product_id = :id
                LIMIT 1";

        $product = $this->db->queryRow($sql, ['id' => $productId, 'lang' => $langId]);
        if (!$product) {
            throw new \RuntimeException("Product with ID {$productId} not found");
        }

        // 1. Categories
        $cats = [];
        try {
            $cats = $this->db->query(
                "SELECT c.category_id, cd.name FROM {prefix}product_to_category ptc
                 LEFT JOIN {prefix}category c ON (ptc.category_id = c.category_id)
                 LEFT JOIN {prefix}category_description cd ON (c.category_id = cd.category_id AND cd.language_id = :lang)
                 WHERE ptc.product_id = :id",
                ['id' => $productId, 'lang' => $langId]
            );
        } catch (\Exception $e) {
            // Ignore if category tables missing
        }

        // 2. Specials / Discounts
        $specials = [];
        try {
            $specials = $this->db->query(
                "SELECT price, date_start, date_end FROM {prefix}product_special
                 WHERE product_id = :id 
                   AND (date_start IS NULL OR date_start = '0000-00-00' OR date_start <= CURDATE())
                   AND (date_end IS NULL OR date_end = '0000-00-00' OR date_end >= CURDATE())
                 ORDER BY priority ASC, price ASC",
                ['id' => $productId]
            );
        } catch (\Exception $e) {
            // Ignore if table missing
        }

        // 3. Attributes
        $attributes = [];
        try {
            $attributes = $this->db->query(
                "SELECT ad.name as attribute, pa.text as value FROM {prefix}product_attribute pa
                 LEFT JOIN {prefix}attribute_description ad ON (pa.attribute_id = ad.attribute_id AND ad.language_id = :lang)
                 WHERE pa.product_id = :id AND pa.language_id = :lang",
                ['id' => $productId, 'lang' => $langId]
            );
        } catch (\Exception $e) {
            // Ignore if table missing
        }

        // 4. Additional Images
        $imageList = [];
        if (!empty($product['image'])) {
            $imageList[] = $product['image'];
        }
        try {
            $images = $this->db->query(
                "SELECT image FROM {prefix}product_image WHERE product_id = :id ORDER BY sort_order ASC",
                ['id' => $productId]
            );
            foreach ($images as $img) {
                $imageList[] = $img['image'];
            }
        } catch (\Exception $e) {
            // Ignore if table missing
        }

        return [
            'product_id'              => (int)$product['product_id'],
            'name'                    => $product['name'],
            'model'                   => $product['model'],
            'sku'                     => $product['sku'],
            'price'                   => (float)$product['price'],
            'formatted_price'         => $this->schema->formatCurrency($product['price']),
            'special_price'           => !empty($specials) ? (float)$specials[0]['price'] : null,
            'formatted_special_price' => !empty($specials) ? $this->schema->formatCurrency($specials[0]['price']) : null,
            'quantity'                => (int)$product['quantity'],
            'stock_status'            => (int)$product['quantity'] > 0 ? 'In Stock' : 'Out of Stock',
            'status'                  => (int)$product['status'] === 1 ? 'Enabled' : 'Disabled',
            'manufacturer'            => $product['manufacturer'],
            'seo_url'                 => $this->schema->getSeoKeyword('product_id=' . $productId),
            'main_category_id'        => $this->schema->getMainCategoryId($productId),
            'categories'              => $cats,
            'attributes'              => $attributes,
            'images'                  => array_unique($imageList),
            'meta_title'              => $product['meta_title'],
            'meta_description'        => $product['meta_description'],
            'date_added'              => $product['date_added'],
            'date_modified'           => $product['date_modified'],
        ];
    }

    /**
     * Search products with fast keyword matching
     *
     * @param array $args
     * @return array
     */
    public function searchProducts(array $args)
    {
        $query = isset($args['query']) ? trim($args['query']) : '';
        if ($query === '') {
            return ['products' => [], 'count' => 0];
        }

        $limit = isset($args['limit']) ? min(50, max(1, (int)$args['limit'])) : 10;
        $langId = $this->schema->getDefaultLanguageId();

        $sql = "SELECT p.product_id, p.model, p.sku, p.price, p.quantity, p.status, pd.name
                FROM {prefix}product p
                LEFT JOIN {prefix}product_description pd ON (p.product_id = pd.product_id AND pd.language_id = :lang)
                WHERE pd.name LIKE :q_name OR p.model LIKE :q_model OR p.sku LIKE :q_sku
                ORDER BY (pd.name LIKE :q_exact) DESC, p.product_id DESC
                LIMIT {$limit}";

        $rows = $this->db->query($sql, [
            'lang'    => $langId,
            'q_name'  => '%' . $query . '%',
            'q_model' => '%' . $query . '%',
            'q_sku'   => '%' . $query . '%',
            'q_exact' => $query . '%',
        ]);

        foreach ($rows as &$r) {
            $r['product_id'] = (int)$r['product_id'];
            $r['price'] = (float)$r['price'];
            $r['quantity'] = (int)$r['quantity'];
            $r['status'] = (int)$r['status'] === 1 ? 'Enabled' : 'Disabled';
            $r['seo_url'] = $this->schema->getSeoKeyword('product_id=' . $r['product_id']);
        }

        return [
            'query'    => $query,
            'count'    => count($rows),
            'products' => $rows,
        ];
    }

    /**
     * Update product stock quantity (requires mutation permission)
     *
     * @param array $args
     * @return array
     * @throws \RuntimeException
     */
    public function updateProductStock(array $args)
    {
        if (empty($args['product_id']) || !isset($args['quantity'])) {
            throw new \RuntimeException("Missing product_id or quantity");
        }

        $productId = (int)$args['product_id'];
        $quantity = (int)$args['quantity'];

        $current = $this->db->queryRow("SELECT quantity, model FROM {prefix}product WHERE product_id = :id", ['id' => $productId]);
        if (!$current) {
            throw new \RuntimeException("Product {$productId} not found");
        }

        $oldQty = (int)$current['quantity'];

        $this->db->execute(
            "UPDATE {prefix}product SET quantity = :qty, date_modified = NOW() WHERE product_id = :id",
            ['qty' => $quantity, 'id' => $productId]
        );

        return [
            'success'      => true,
            'product_id'   => $productId,
            'model'        => $current['model'],
            'old_quantity' => $oldQty,
            'new_quantity' => $quantity,
            'delta'        => ($quantity - $oldQty),
        ];
    }

    /**
     * Update product price (requires mutation permission)
     *
     * @param array $args
     * @return array
     * @throws \RuntimeException
     */
    public function updateProductPrice(array $args)
    {
        if (empty($args['product_id']) || !isset($args['price'])) {
            throw new \RuntimeException("Missing product_id or price");
        }

        $productId = (int)$args['product_id'];
        $price = (float)$args['price'];
        if ($price < 0) {
            throw new \RuntimeException("Price cannot be negative");
        }

        $current = $this->db->queryRow("SELECT price, model FROM {prefix}product WHERE product_id = :id", ['id' => $productId]);
        if (!$current) {
            throw new \RuntimeException("Product {$productId} not found");
        }

        $oldPrice = (float)$current['price'];

        $this->db->execute(
            "UPDATE {prefix}product SET price = :price, date_modified = NOW() WHERE product_id = :id",
            ['price' => $price, 'id' => $productId]
        );

        return [
            'success'   => true,
            'product_id'=> $productId,
            'model'     => $current['model'],
            'old_price' => $oldPrice,
            'new_price' => $price,
            'currency'  => $this->schema->getDefaultCurrency(),
        ];
    }
}
