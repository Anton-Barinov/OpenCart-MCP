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

class AnalyticsTools
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
     * Sales performance summary
     *
     * @param array $args
     * @return array
     */
    public function salesSummary(array $args)
    {
        $days = isset($args['days']) ? max(1, (int)$args['days']) : 30;

        // Overall aggregate
        $sql = "SELECT COUNT(*) as total_orders,
                       COALESCE(SUM(total), 0) as total_revenue,
                       COALESCE(AVG(total), 0) as avg_order_value
                FROM {prefix}order
                WHERE order_status_id > 0
                  AND date_added >= DATE_SUB(NOW(), INTERVAL :days DAY)";

        $summary = $this->db->queryRow($sql, ['days' => $days]);

        // Daily breakdown
        $dailySql = "SELECT DATE(date_added) as order_date,
                            COUNT(*) as orders_count,
                            COALESCE(SUM(total), 0) as revenue
                     FROM {prefix}order
                     WHERE order_status_id > 0
                       AND date_added >= DATE_SUB(NOW(), INTERVAL :days DAY)
                     GROUP BY DATE(date_added)
                     ORDER BY order_date DESC";

        $daily = $this->db->query($dailySql, ['days' => $days]);

        foreach ($daily as &$d) {
            $d['orders_count'] = (int)$d['orders_count'];
            $d['revenue'] = (float)$d['revenue'];
        }

        return [
            'period_days'     => $days,
            'currency'        => $this->schema->getDefaultCurrency(),
            'total_orders'    => (int)$summary['total_orders'],
            'total_revenue'   => (float)$summary['total_revenue'],
            'avg_order_value' => round((float)$summary['avg_order_value'], 2),
            'daily_breakdown' => $daily,
        ];
    }

    /**
     * Report on products running low on stock
     *
     * @param array $args
     * @return array
     */
    public function lowStockReport(array $args)
    {
        $threshold = isset($args['threshold']) ? (int)$args['threshold'] : 5;
        $limit = isset($args['limit']) ? min(100, max(1, (int)$args['limit'])) : 50;
        $langId = $this->schema->getDefaultLanguageId();

        $sql = "SELECT p.product_id, p.model, p.sku, p.quantity, p.price, pd.name
                FROM {prefix}product p
                LEFT JOIN {prefix}product_description pd ON (p.product_id = pd.product_id AND pd.language_id = :lang)
                WHERE p.status = 1 AND p.quantity <= :threshold
                ORDER BY p.quantity ASC, p.product_id ASC
                LIMIT {$limit}";

        $rows = $this->db->query($sql, ['lang' => $langId, 'threshold' => $threshold]);

        foreach ($rows as &$r) {
            $r['product_id'] = (int)$r['product_id'];
            $r['quantity'] = (int)$r['quantity'];
            $r['price'] = (float)$r['price'];
            $r['status'] = $r['quantity'] === 0 ? 'Out of Stock' : 'Low Stock';
        }

        return [
            'threshold'       => $threshold,
            'low_stock_count' => count($rows),
            'products'        => $rows,
        ];
    }

    /**
     * Bestselling products by sales volume
     *
     * @param array $args
     * @return array
     */
    public function topProducts(array $args)
    {
        $days = isset($args['days']) ? max(1, (int)$args['days']) : 30;
        $limit = isset($args['limit']) ? min(50, max(1, (int)$args['limit'])) : 10;
        $langId = $this->schema->getDefaultLanguageId();

        $sql = "SELECT op.product_id, op.name, op.model,
                       SUM(op.quantity) as total_quantity_sold,
                       SUM(op.total) as total_revenue
                FROM {prefix}order_product op
                JOIN {prefix}order o ON (op.order_id = o.order_id)
                WHERE o.order_status_id > 0
                  AND o.date_added >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY op.product_id, op.name, op.model
                ORDER BY total_quantity_sold DESC
                LIMIT {$limit}";

        $rows = $this->db->query($sql, ['days' => $days]);

        foreach ($rows as &$r) {
            $r['product_id'] = (int)$r['product_id'];
            $r['total_quantity_sold'] = (int)$r['total_quantity_sold'];
            $r['total_revenue'] = (float)$r['total_revenue'];
        }

        return [
            'period_days'  => $days,
            'top_products' => $rows,
        ];
    }
}
