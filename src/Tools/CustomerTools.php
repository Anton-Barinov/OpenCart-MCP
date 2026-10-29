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
use OpenCartMcp\Core\SecurityPolicy;

class CustomerTools
{
    /** @var Database */
    private $db;

    /** @var SecurityPolicy */
    private $security;

    public function __construct(Database $db, SecurityPolicy $security)
    {
        $this->db = $db;
        $this->security = $security;
    }

    /**
     * List customers with search and PII protection
     *
     * @param array $args
     * @return array
     */
    public function listCustomers(array $args)
    {
        $limit = isset($args['limit']) ? min(100, max(1, (int)$args['limit'])) : 20;
        $offset = isset($args['offset']) ? max(0, (int)$args['offset']) : 0;

        $where = ["1=1"];
        $params = [];

        if (!empty($args['filter_name'])) {
            $where[] = "(CONCAT(c.firstname, ' ', c.lastname) LIKE :fname OR c.email LIKE :fmail)";
            $params['fname'] = '%' . $args['filter_name'] . '%';
            $params['fmail'] = '%' . $args['filter_name'] . '%';
        }

        $whereClause = implode(" AND ", $where);
        $sql = "SELECT c.customer_id, c.customer_group_id, c.firstname, c.lastname, c.email, c.telephone,
                       c.status, c.date_added,
                       (SELECT COUNT(*) FROM {prefix}order o WHERE o.customer_id = c.customer_id) as total_orders,
                       (SELECT COALESCE(SUM(o.total), 0) FROM {prefix}order o WHERE o.customer_id = c.customer_id AND o.order_status_id > 0) as total_spent
                FROM {prefix}customer c
                WHERE {$whereClause}
                ORDER BY c.customer_id DESC
                LIMIT {$offset}, {$limit}";

        $rows = $this->db->query($sql, $params);

        foreach ($rows as &$r) {
            $r['customer_id'] = (int)$r['customer_id'];
            $r['status'] = (int)$r['status'] === 1 ? 'Active' : 'Disabled';
            $r['total_orders'] = (int)$r['total_orders'];
            $r['total_spent'] = (float)$r['total_spent'];
            $r = $this->security->sanitizeCustomerRecord($r);
        }

        $countSql = "SELECT COUNT(*) FROM {prefix}customer c WHERE {$whereClause}";
        $total = (int)$this->db->queryScalar($countSql, $params);

        return [
            'total'     => $total,
            'limit'     => $limit,
            'offset'    => $offset,
            'customers' => $rows,
        ];
    }

    /**
     * Get single customer profile
     *
     * @param array $args
     * @return array
     * @throws \RuntimeException
     */
    public function getCustomer(array $args)
    {
        if (empty($args['customer_id'])) {
            throw new \RuntimeException("Missing customer_id parameter");
        }

        $customerId = (int)$args['customer_id'];
        $sql = "SELECT customer_id, customer_group_id, firstname, lastname, email, telephone, status, date_added
                FROM {prefix}customer WHERE customer_id = :id LIMIT 1";

        $customer = $this->db->queryRow($sql, ['id' => $customerId]);
        if (!$customer) {
            throw new \RuntimeException("Customer #{$customerId} not found");
        }

        // Addresses
        $addresses = $this->db->query(
            "SELECT a.address_id, a.address_1, a.city, a.postcode, c.name as country, z.name as zone
             FROM {prefix}address a
             LEFT JOIN {prefix}country c ON (a.country_id = c.country_id)
             LEFT JOIN {prefix}zone z ON (a.zone_id = z.zone_id)
             WHERE a.customer_id = :id",
            ['id' => $customerId]
        );

        $customer['customer_id'] = (int)$customer['customer_id'];
        $customer['status'] = (int)$customer['status'] === 1 ? 'Active' : 'Disabled';
        $customer['addresses'] = $addresses;

        return $this->security->sanitizeCustomerRecord($customer);
    }
}
