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

class CategoryTools
{
    /** @var Database */
    private $db;

    /** @var SchemaAdapter */
    private $schema;

    public function __construct(Database $db, SchemaAdapter $schema)
    {
        $this->db = $db;
        $this->schema = $schema;
    }

    /**
     * List categories with optional parent_id filtering
     *
     * @param array $args
     * @return array
     */
    public function listCategories(array $args)
    {
        $langId = $this->schema->getDefaultLanguageId();
        $parentId = isset($args['parent_id']) ? (int)$args['parent_id'] : null;
        $limit = isset($args['limit']) ? min(100, max(1, (int)$args['limit'])) : 50;
        $offset = isset($args['offset']) ? max(0, (int)$args['offset']) : 0;

        $where = ["cd.language_id = " . (int)$langId];
        $params = [];

        if ($parentId !== null) {
            $where[] = "c.parent_id = :parent";
            $params['parent'] = $parentId;
        }

        $whereClause = implode(" AND ", $where);
        $sql = "SELECT c.category_id, c.parent_id, c.sort_order, c.status, cd.name,
                       (SELECT COUNT(*) FROM {prefix}product_to_category ptc WHERE ptc.category_id = c.category_id) as total_products
                FROM {prefix}category c
                LEFT JOIN {prefix}category_description cd ON (c.category_id = cd.category_id)
                WHERE {$whereClause}
                ORDER BY c.sort_order ASC, cd.name ASC
                LIMIT {$offset}, {$limit}";

        $rows = $this->db->query($sql, $params);

        foreach ($rows as &$r) {
            $r['category_id'] = (int)$r['category_id'];
            $r['parent_id'] = (int)$r['parent_id'];
            $r['status'] = (int)$r['status'] === 1 ? 'Enabled' : 'Disabled';
            $r['total_products'] = (int)$r['total_products'];
            $r['seo_url'] = $this->schema->getSeoKeyword('category_id=' . $r['category_id']);
        }

        return [
            'count'      => count($rows),
            'categories' => $rows,
        ];
    }

    /**
     * Get complete category hierarchy tree
     *
     * @param array $args
     * @return array
     */
    public function getCategoryTree(array $args = [])
    {
        $langId = $this->schema->getDefaultLanguageId();
        $sql = "SELECT c.category_id, c.parent_id, cd.name
                FROM {prefix}category c
                LEFT JOIN {prefix}category_description cd ON (c.category_id = cd.category_id AND cd.language_id = :lang)
                WHERE c.status = 1
                ORDER BY c.sort_order ASC, cd.name ASC";

        $rows = $this->db->query($sql, ['lang' => $langId]);

        // Build adjacency map
        $byId = [];
        foreach ($rows as $r) {
            $id = (int)$r['category_id'];
            $byId[$id] = [
                'category_id' => $id,
                'name'        => $r['name'],
                'parent_id'   => (int)$r['parent_id'],
                'children'    => [],
            ];
        }

        $tree = [];
        foreach ($byId as $id => &$node) {
            $parent = $node['parent_id'];
            if ($parent > 0 && isset($byId[$parent])) {
                $byId[$parent]['children'][] = &$node;
            } else {
                $tree[] = &$node;
            }
        }

        return ['tree' => $tree];
    }
}
