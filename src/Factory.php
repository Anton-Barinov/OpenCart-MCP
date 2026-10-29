<?php
/**
 * OpenCart Universal MCP Server
 * Compatible with PHP 5.4 - 8.4+
 *
 * @author    Anton Barinov <https://github.com/Anton-Barinov>
 * @license   MIT
 */

namespace OpenCartMcp;

use OpenCartMcp\Core\OpenCartDetector;
use OpenCartMcp\Core\Database;
use OpenCartMcp\Core\SchemaAdapter;
use OpenCartMcp\Core\SecurityPolicy;
use OpenCartMcp\Protocol\McpServer;
use OpenCartMcp\Tools\ProductTools;
use OpenCartMcp\Tools\CategoryTools;
use OpenCartMcp\Tools\OrderTools;
use OpenCartMcp\Tools\CustomerTools;
use OpenCartMcp\Tools\AnalyticsTools;
use OpenCartMcp\Tools\SystemTools;

class Factory
{
    /**
     * Create and configure complete McpServer for OpenCart
     *
     * @param array $options
     * @return McpServer
     * @throws \RuntimeException
     */
    public static function create(array $options = [])
    {
        // 1. Resolve OpenCart Root
        $rootPath = isset($options['root']) ? $options['root'] : '';
        if (!$rootPath) {
            $found = OpenCartDetector::findRoot();
            $rootPath = $found ? $found : getcwd();
        }

        $detector = new OpenCartDetector($rootPath);

        // 2. Resolve Database Credentials
        $dbConfig = [
            'host'     => isset($options['db_host']) ? $options['db_host'] : $detector->getDbHostname(),
            'user'     => isset($options['db_user']) ? $options['db_user'] : $detector->getDbUsername(),
            'password' => isset($options['db_password']) ? $options['db_password'] : $detector->getDbPassword(),
            'database' => isset($options['db_name']) ? $options['db_name'] : $detector->getDbDatabase(),
            'port'     => isset($options['db_port']) ? (int)$options['db_port'] : $detector->getDbPort(),
            'prefix'   => isset($options['db_prefix']) ? $options['db_prefix'] : $detector->getDbPrefix(),
            'readOnly' => !isset($options['allow_mutations']) || !$options['allow_mutations'],
        ];

        if (empty($dbConfig['database'])) {
            throw new \RuntimeException(
                "OpenCart database not specified and could not be detected automatically. " .
                "Pass --root=/path/to/opencart or configure DB options via --db-name=<database>."
            );
        }

        $db = new Database($dbConfig);
        $detector->sniffVersionFromDb($db);

        $schema = new SchemaAdapter($db, $detector->getMajorVersion());
        $security = new SecurityPolicy([
            'maskPii'        => !isset($options['mask_pii']) || (bool)$options['mask_pii'],
            'allowMutations' => isset($options['allow_mutations']) && (bool)$options['allow_mutations'],
            'token'          => isset($options['token']) ? $options['token'] : null,
            'ipWhitelist'    => isset($options['ip_whitelist']) ? (array)$options['ip_whitelist'] : [],
        ]);

        $server = new McpServer('OpenCart-Universal-MCP', '1.0.0');

        // Instantiate tool groups
        $productTools = new ProductTools($db, $schema, $security);
        $categoryTools = new CategoryTools($db, $schema);
        $orderTools = new OrderTools($db, $schema, $security);
        $customerTools = new CustomerTools($db, $security);
        $analyticsTools = new AnalyticsTools($db, $schema);
        $systemTools = new SystemTools($db, $schema, $detector);

        // --- Register Product Tools ---
        $server->registerTool(
            'oc_list_products',
            'List products with filtering by category, search term, stock status, and pagination',
            [
                'type'       => 'object',
                'properties' => [
                    'category_id'   => ['type' => 'integer', 'description' => 'Optional category ID to filter by'],
                    'filter_name'   => ['type' => 'string', 'description' => 'Filter by product name, model, or SKU'],
                    'filter_status' => ['type' => 'integer', 'description' => 'Filter by status (1 = enabled, 0 = disabled)'],
                    'limit'         => ['type' => 'integer', 'description' => 'Number of products to return (default: 20, max: 100)'],
                    'offset'        => ['type' => 'integer', 'description' => 'Offset for pagination (default: 0)'],
                ],
            ],
            [$productTools, 'listProducts']
        );

        $server->registerTool(
            'oc_get_product',
            'Get comprehensive product details (prices, attributes, options, SEO URL, images, categories)',
            [
                'type'       => 'object',
                'properties' => [
                    'product_id' => ['type' => 'integer', 'description' => 'The ID of the product to inspect'],
                ],
                'required'   => ['product_id'],
            ],
            [$productTools, 'getProduct']
        );

        $server->registerTool(
            'oc_search_products',
            'Fast keyword search for products across name, model, SKU, and tags',
            [
                'type'       => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Keyword or SKU to search for'],
                    'limit' => ['type' => 'integer', 'description' => 'Max number of results (default: 10)'],
                ],
                'required'   => ['query'],
            ],
            [$productTools, 'searchProducts']
        );

        $server->registerTool(
            'oc_update_product_stock',
            'Update product stock inventory quantity (requires mutation permission)',
            [
                'type'       => 'object',
                'properties' => [
                    'product_id' => ['type' => 'integer', 'description' => 'Product ID'],
                    'quantity'   => ['type' => 'integer', 'description' => 'New stock quantity'],
                ],
                'required'   => ['product_id', 'quantity'],
            ],
            [$productTools, 'updateProductStock']
        );

        $server->registerTool(
            'oc_update_product_price',
            'Update base price for a product (requires mutation permission)',
            [
                'type'       => 'object',
                'properties' => [
                    'product_id' => ['type' => 'integer', 'description' => 'Product ID'],
                    'price'      => ['type' => 'number', 'description' => 'New base price in store currency'],
                ],
                'required'   => ['product_id', 'price'],
            ],
            [$productTools, 'updateProductPrice']
        );

        // --- Register Category Tools ---
        $server->registerTool(
            'oc_list_categories',
            'List categories with total product counts and parent hierarchy',
            [
                'type'       => 'object',
                'properties' => [
                    'parent_id' => ['type' => 'integer', 'description' => 'Optional parent category ID'],
                    'limit'     => ['type' => 'integer', 'description' => 'Limit (default: 50)'],
                    'offset'    => ['type' => 'integer', 'description' => 'Offset (default: 0)'],
                ],
            ],
            [$categoryTools, 'listCategories']
        );

        $server->registerTool(
            'oc_get_category_tree',
            'Retrieve complete nested category tree hierarchy',
            [
                'type'       => 'object',
                'properties' => new \stdClass(),
            ],
            [$categoryTools, 'getCategoryTree']
        );

        // --- Register Order Tools ---
        $server->registerTool(
            'oc_list_orders',
            'List store orders with status and date filtering and PII masking',
            [
                'type'       => 'object',
                'properties' => [
                    'filter_order_status_id' => ['type' => 'integer', 'description' => 'Order status ID filter'],
                    'filter_date_added'      => ['type' => 'string', 'description' => 'Filter by date added (YYYY-MM-DD)'],
                    'limit'                  => ['type' => 'integer', 'description' => 'Limit (default: 20)'],
                    'offset'                 => ['type' => 'integer', 'description' => 'Offset (default: 0)'],
                ],
            ],
            [$orderTools, 'listOrders']
        );

        $server->registerTool(
            'oc_get_order',
            'Get complete order breakdown: items, options, totals, shipping, and history',
            [
                'type'       => 'object',
                'properties' => [
                    'order_id' => ['type' => 'integer', 'description' => 'Order ID'],
                ],
                'required'   => ['order_id'],
            ],
            [$orderTools, 'getOrder']
        );

        $server->registerTool(
            'oc_update_order_status',
            'Update order status and optionally notify customer (requires mutation permission)',
            [
                'type'       => 'object',
                'properties' => [
                    'order_id'        => ['type' => 'integer', 'description' => 'Order ID'],
                    'order_status_id' => ['type' => 'integer', 'description' => 'New order status ID'],
                    'comment'         => ['type' => 'string', 'description' => 'Status change comment'],
                    'notify'          => ['type' => 'integer', 'description' => 'Notify customer via email (1 = yes, 0 = no)'],
                ],
                'required'   => ['order_id', 'order_status_id'],
            ],
            [$orderTools, 'updateOrderStatus']
        );

        // --- Register Customer Tools ---
        $server->registerTool(
            'oc_list_customers',
            'List customer accounts with order counts and spent totals (PII masked)',
            [
                'type'       => 'object',
                'properties' => [
                    'filter_name' => ['type' => 'string', 'description' => 'Filter by name or email'],
                    'limit'       => ['type' => 'integer', 'description' => 'Limit (default: 20)'],
                    'offset'      => ['type' => 'integer', 'description' => 'Offset (default: 0)'],
                ],
            ],
            [$customerTools, 'listCustomers']
        );

        $server->registerTool(
            'oc_get_customer',
            'Get customer profile details and addresses (PII masked)',
            [
                'type'       => 'object',
                'properties' => [
                    'customer_id' => ['type' => 'integer', 'description' => 'Customer ID'],
                ],
                'required'   => ['customer_id'],
            ],
            [$customerTools, 'getCustomer']
        );

        // --- Register Analytics Tools ---
        $server->registerTool(
            'oc_sales_summary',
            'Sales revenue, total orders, and average order value breakdown over N days',
            [
                'type'       => 'object',
                'properties' => [
                    'days' => ['type' => 'integer', 'description' => 'Lookback days (default: 30)'],
                ],
            ],
            [$analyticsTools, 'salesSummary']
        );

        $server->registerTool(
            'oc_low_stock_report',
            'Report products with stock at or below a specified threshold',
            [
                'type'       => 'object',
                'properties' => [
                    'threshold' => ['type' => 'integer', 'description' => 'Quantity threshold (default: 5)'],
                    'limit'     => ['type' => 'integer', 'description' => 'Limit (default: 50)'],
                ],
            ],
            [$analyticsTools, 'lowStockReport']
        );

        $server->registerTool(
            'oc_top_products',
            'Top bestselling products by quantity sold and revenue over N days',
            [
                'type'       => 'object',
                'properties' => [
                    'days'  => ['type' => 'integer', 'description' => 'Lookback days (default: 30)'],
                    'limit' => ['type' => 'integer', 'description' => 'Limit (default: 10)'],
                ],
            ],
            [$analyticsTools, 'topProducts']
        );

        // --- Register System Tools ---
        $server->registerTool(
            'oc_system_info',
            'Get OpenCart version, PHP version, store statistics, and database status',
            [
                'type'       => 'object',
                'properties' => new \stdClass(),
            ],
            [$systemTools, 'systemInfo']
        );

        $server->registerTool(
            'oc_read_error_log',
            'Read the last N lines of the OpenCart error.log safely',
            [
                'type'       => 'object',
                'properties' => [
                    'lines' => ['type' => 'integer', 'description' => 'Number of lines to read (default: 50, max: 500)'],
                ],
            ],
            [$systemTools, 'readErrorLog']
        );

        $server->registerTool(
            'oc_list_modifications',
            'List all installed OCMOD modifications and their enabled status',
            [
                'type'       => 'object',
                'properties' => new \stdClass(),
            ],
            [$systemTools, 'listModifications']
        );

        // --- Register Resources ---
        $server->registerResource(
            'opencart://system/info',
            'OpenCart System Information',
            'Overview of OpenCart store configuration and runtime stats',
            'application/json',
            function () use ($systemTools) {
                return $systemTools->systemInfo();
            }
        );

        $server->registerResource(
            'opencart://catalog/summary',
            'Catalog Summary',
            'Quick overview of active categories and low stock items',
            'application/json',
            function () use ($analyticsTools, $categoryTools) {
                return [
                    'categories' => $categoryTools->listCategories(['limit' => 10]),
                    'low_stock'  => $analyticsTools->lowStockReport(['threshold' => 3, 'limit' => 10]),
                ];
            }
        );

        // --- Register Prompts ---
        $server->registerPrompt(
            'product_seo_optimizer',
            'Generate optimized meta title, meta description, and keywords for a product',
            [
                ['name' => 'product_id', 'description' => 'The ID of the product to optimize', 'required' => true],
            ],
            function (array $args) use ($productTools) {
                $product = $productTools->getProduct(['product_id' => $args['product_id']]);
                return [
                    [
                        'role'    => 'user',
                        'content' => [
                            'type' => 'text',
                            'text' => "Please analyze this OpenCart product and generate an SEO-optimized meta title (under 60 chars), meta description (under 160 chars), and 5 targeted search keywords in the store's language:\n\n" .
                                      json_encode($product, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                        ],
                    ]
                ];
            }
        );

        $server->registerPrompt(
            'daily_business_brief',
            'Generate a morning executive summary of yesterday\'s sales and inventory alerts',
            [],
            function (array $args) use ($analyticsTools, $orderTools) {
                $sales = $analyticsTools->salesSummary(['days' => 1]);
                $lowStock = $analyticsTools->lowStockReport(['threshold' => 2, 'limit' => 5]);
                return [
                    [
                        'role'    => 'user',
                        'content' => [
                            'type' => 'text',
                            'text' => "You are an executive e-commerce assistant. Review the following recent performance data from OpenCart and give the store manager a concise, actionable morning brief:\n\n" .
                                      "Sales (24h): " . json_encode($sales, JSON_PRETTY_PRINT) . "\n\n" .
                                      "Critical Low Stock: " . json_encode($lowStock, JSON_PRETTY_PRINT),
                        ],
                    ]
                ];
            }
        );

        return $server;
    }
}
