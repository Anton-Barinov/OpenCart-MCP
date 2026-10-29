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

class OrderTools
{
    /** @var Database */
    private $db;

    /** @var SchemaAdapter */
    private $schema;

    /** @var SecurityPolicy */
    private $security;

    public function __construct(Database $db, SchemaAdapter $schema, SecurityPolicy $security)
    {
        $this->db = $db;
        $this->schema = $schema;
        $this->security = $security;
    }

    /**
     * List recent orders with status filtering and PII sanitization
     *
     * @param array $args
     * @return array
     */
    public function listOrders(array $args)
    {
        $limit = isset($args['limit']) ? min(100, max(1, (int)$args['limit'])) : 20;
        $offset = isset($args['offset']) ? max(0, (int)$args['offset']) : 0;

        $where = ["1=1"];
        $params = [];

        if (isset($args['filter_order_status_id']) && $args['filter_order_status_id'] !== '') {
            $where[] = "o.order_status_id = :status_id";
            $params['status_id'] = (int)$args['filter_order_status_id'];
        }

        if (!empty($args['filter_date_added'])) {
            $where[] = "DATE(o.date_added) = :dadded";
            $params['dadded'] = $args['filter_date_added'];
        }

        $whereClause = implode(" AND ", $where);
        $sql = "SELECT o.order_id, o.customer_id, o.firstname, o.lastname, o.telephone, o.email,
                       o.total, o.currency_code, o.order_status_id, o.date_added
                FROM {prefix}order o
                WHERE {$whereClause}
                ORDER BY o.order_id DESC
                LIMIT {$offset}, {$limit}";

        $rows = $this->db->query($sql, $params);

        foreach ($rows as &$r) {
            $r['order_id'] = (int)$r['order_id'];
            $r['total'] = (float)$r['total'];
            $r['status_name'] = $this->schema->getOrderStatusName($r['order_status_id']);
            $r = $this->security->sanitizeOrderRecord($r);
        }

        $totalSql = "SELECT COUNT(*) FROM {prefix}order o WHERE {$whereClause}";
        $total = (int)$this->db->queryScalar($totalSql, $params);

        return [
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
            'orders' => $rows,
        ];
    }

    /**
     * Get full order details by order_id
     *
     * @param array $args
     * @return array
     * @throws \RuntimeException
     */
    public function getOrder(array $args)
    {
        if (empty($args['order_id'])) {
            throw new \RuntimeException("Missing required parameter: order_id");
        }

        $orderId = (int)$args['order_id'];

        $sql = "SELECT * FROM {prefix}order WHERE order_id = :id LIMIT 1";
        $order = $this->db->queryRow($sql, ['id' => $orderId]);
        if (!$order) {
            throw new \RuntimeException("Order #{$orderId} not found");
        }

        // 1. Order Products
        $products = $this->db->query(
            "SELECT op.order_product_id, op.product_id, op.name, op.model, op.quantity, op.price, op.total
             FROM {prefix}order_product op
             WHERE op.order_id = :id",
            ['id' => $orderId]
        );

        foreach ($products as &$p) {
            $p['order_product_id'] = (int)$p['order_product_id'];
            $p['product_id'] = (int)$p['product_id'];
            $p['quantity'] = (int)$p['quantity'];
            $p['price'] = (float)$p['price'];
            $p['total'] = (float)$p['total'];

            // Attach product options
            $options = $this->db->query(
                "SELECT name, value, type FROM {prefix}order_option WHERE order_product_id = :opid",
                ['opid' => $p['order_product_id']]
            );
            $p['options'] = $options;
        }

        // 2. Order Totals
        $totals = $this->db->query(
            "SELECT code, title, value, sort_order FROM {prefix}order_total WHERE order_id = :id ORDER BY sort_order ASC",
            ['id' => $orderId]
        );

        // 3. Status History
        $history = $this->db->query(
            "SELECT oh.order_status_id, os.name as status_name, oh.notify, oh.comment, oh.date_added
             FROM {prefix}order_history oh
             LEFT JOIN {prefix}order_status os ON (oh.order_status_id = os.order_status_id AND os.language_id = :lang)
             WHERE oh.order_id = :id
             ORDER BY oh.date_added ASC",
            ['id' => $orderId, 'lang' => $this->schema->getDefaultLanguageId()]
        );

        $order['status_name'] = $this->schema->getOrderStatusName($order['order_status_id']);
        $order = $this->security->sanitizeOrderRecord($order);

        return [
            'order_id'            => (int)$order['order_id'],
            'customer'            => [
                'customer_id' => (int)$order['customer_id'],
                'name'        => trim($order['firstname'] . ' ' . $order['lastname']),
                'email'       => $order['email'],
                'telephone'   => $order['telephone'],
            ],
            'shipping_method'     => $order['shipping_method'],
            'shipping_city'       => $order['shipping_city'],
            'shipping_country'    => $order['shipping_country'],
            'payment_method'      => $order['payment_method'],
            'total'               => (float)$order['total'],
            'currency_code'       => $order['currency_code'],
            'order_status_id'     => (int)$order['order_status_id'],
            'status_name'         => $order['status_name'],
            'date_added'          => $order['date_added'],
            'date_modified'       => $order['date_modified'],
            'products'            => $products,
            'totals'              => $totals,
            'history'             => $history,
        ];
    }

    /**
     * Update order status with optional customer notification and comment
     *
     * @param array $args
     * @return array
     * @throws \RuntimeException
     */
    public function updateOrderStatus(array $args)
    {
        if (empty($args['order_id']) || !isset($args['order_status_id'])) {
            throw new \RuntimeException("Missing order_id or order_status_id");
        }

        $orderId = (int)$args['order_id'];
        $newStatusId = (int)$args['order_status_id'];
        $comment = isset($args['comment']) ? (string)$args['comment'] : '';
        $notify = isset($args['notify']) ? (int)$args['notify'] : 0;

        $order = $this->db->queryRow("SELECT order_id, order_status_id FROM {prefix}order WHERE order_id = :id", ['id' => $orderId]);
        if (!$order) {
            throw new \RuntimeException("Order #{$orderId} not found");
        }

        $oldStatusId = (int)$order['order_status_id'];

        // 1. Update order table
        $this->db->execute(
            "UPDATE {prefix}order SET order_status_id = :status, date_modified = NOW() WHERE order_id = :id",
            ['status' => $newStatusId, 'id' => $orderId]
        );

        // 2. Insert into order_history
        $this->db->execute(
            "INSERT INTO {prefix}order_history (order_id, order_status_id, notify, comment, date_added)
             VALUES (:id, :status, :notify, :comment, NOW())",
            [
                'id'      => $orderId,
                'status'  => $newStatusId,
                'notify'  => $notify,
                'comment' => $comment,
            ]
        );

        return [
            'success'             => true,
            'order_id'            => $orderId,
            'old_order_status_id' => $oldStatusId,
            'new_order_status_id' => $newStatusId,
            'old_status_name'     => $this->schema->getOrderStatusName($oldStatusId),
            'new_status_name'     => $this->schema->getOrderStatusName($newStatusId),
            'comment'             => $comment,
            'notified'            => ($notify === 1),
        ];
    }
}
