<?php
/**
 * OpenCart Universal MCP Server
 * End-To-End Tools & Data Verification Test
 */

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/Fixtures/SchemaFixture.php';

use OpenCartMcp\Core\Database;
use OpenCartMcp\Factory;
use OpenCartMcp\Protocol\JsonRpcRequest;
use OpenCartMcp\Tests\Fixtures\SchemaFixture;

echo "=== Running EndToEndToolsTest ===\n";

$dbConfig = [
    'host'     => '127.0.0.1',
    'user'     => 'root',
    'password' => '',
    'database' => 'oc_mcp_test',
    'port'     => 3306,
    'prefix'   => 'oc_',
    'readOnly' => false,
];

try {
    $db = new Database($dbConfig);
    SchemaFixture::setUp($db);
    echo "  ✓ Test database seeded successfully with mock OpenCart schema\n";
} catch (\Exception $e) {
    echo "SKIP: MySQL not available or failed to seed: " . $e->getMessage() . "\n";
    exit(0);
}

// 1. Initialize Read-Only Server
$server = Factory::create([
    'db_host'         => '127.0.0.1',
    'db_user'         => 'root',
    'db_password'     => '',
    'db_name'         => 'oc_mcp_test',
    'db_prefix'       => 'oc_',
    'allow_mutations' => false,
    'mask_pii'        => true,
]);

function callTool($server, $name, array $args = []) {
    $req = new JsonRpcRequest(1, 'tools/call', [
        'name'      => $name,
        'arguments' => $args,
    ]);
    $res = $server->handleRequest($req);
    $arr = $res->toArray();
    return $arr['result'];
}

// Test 1: oc_list_products
$resProducts = callTool($server, 'oc_list_products', ['limit' => 5]);
assert($resProducts['isError'] === false, 'oc_list_products succeeded');
assert($resProducts['structuredContent']['total'] === 3, 'Found 3 products');
assert($resProducts['structuredContent']['products'][0]['seo_url'] === 'apple-macbook-air-m3', 'SEO URL resolved');
echo "  ✓ oc_list_products passed\n";

// Test 2: oc_get_product
$resProduct = callTool($server, 'oc_get_product', ['product_id' => 101]);
assert($resProduct['isError'] === false, 'oc_get_product succeeded');
$p = $resProduct['structuredContent'];
assert($p['model'] === 'iPhone 15 Pro', 'Product model matches');
assert($p['manufacturer'] === 'Apple', 'Manufacturer matches');
assert(count($p['categories']) === 1, 'Categories attached');
echo "  ✓ oc_get_product passed\n";

// Test 3: oc_search_products
$resSearch = callTool($server, 'oc_search_products', ['query' => 'MacBook']);
assert($resSearch['structuredContent']['count'] === 1, 'Found MacBook');
assert($resSearch['structuredContent']['products'][0]['product_id'] === 103, 'Product ID 103');
echo "  ✓ oc_search_products passed\n";

// Test 4: oc_list_categories & oc_get_category_tree
$resCats = callTool($server, 'oc_list_categories');
assert($resCats['structuredContent']['count'] === 3, '3 categories found');

$resTree = callTool($server, 'oc_get_category_tree');
assert(count($resTree['structuredContent']['tree']) === 1, '1 top level category (Electronics)');
assert(count($resTree['structuredContent']['tree'][0]['children']) === 2, '2 subcategories (Smartphones, Laptops)');
echo "  ✓ Category tools and tree hierarchy passed\n";

// Test 5: oc_list_orders & oc_get_order
$resOrders = callTool($server, 'oc_list_orders');
assert($resOrders['structuredContent']['total'] === 2, '2 orders found');
$firstOrder = $resOrders['structuredContent']['orders'][0];
assert(strpos($firstOrder['email'], '***') !== false, 'Customer email masked in orders');

$resOrderDetails = callTool($server, 'oc_get_order', ['order_id' => 501]);
assert($resOrderDetails['isError'] === false, 'Order details retrieved');
assert(count($resOrderDetails['structuredContent']['products']) === 1, 'Order product retrieved');
assert(count($resOrderDetails['structuredContent']['history']) === 2, 'Order history retrieved');
echo "  ✓ Order tools and history passed\n";

// Test 6: oc_list_customers & PII Masking
$resCustomers = callTool($server, 'oc_list_customers');
assert($resCustomers['structuredContent']['total'] === 2, '2 customers found');
$c = $resCustomers['structuredContent']['customers'][0];
assert($c['firstname'] === 'E***', 'Customer firstname masked');
assert($c['email'] === 'el***@testmail.org', 'Customer email masked');
assert(!isset($c['password']), 'Password hash is completely absent');
echo "  ✓ Customer tools and GDPR PII masking passed\n";

// Test 7: Analytics (Sales Summary, Low Stock, Top Products)
$resSales = callTool($server, 'oc_sales_summary', ['days' => 30]);
assert($resSales['structuredContent']['total_orders'] === 2, '2 sales recorded');
assert($resSales['structuredContent']['total_revenue'] === 1798.0, 'Total revenue 1798.0');

$resLowStock = callTool($server, 'oc_low_stock_report', ['threshold' => 2]);
assert($resLowStock['structuredContent']['low_stock_count'] === 2, 'MacBook (0) and Galaxy S24 (2) detected as low stock');

$resTop = callTool($server, 'oc_top_products', ['days' => 30]);
assert(count($resTop['structuredContent']['top_products']) === 2, 'Top products returned');
echo "  ✓ Analytics & inventory reporting passed\n";

// Test 8: oc_system_info & oc_list_modifications
$resSys = callTool($server, 'oc_system_info');
assert($resSys['structuredContent']['store_name'] === 'Demo OpenCart Store', 'Store name matches');
assert($resSys['structuredContent']['statistics']['total_products'] === 3, 'Product count matches');

$resMod = callTool($server, 'oc_list_modifications');
assert($resMod['structuredContent']['count'] === 1, 'OCMOD modification detected');
echo "  ✓ System tools and modification inspection passed\n";

// Test 9: Read-Only Safety Violation
$resWriteAttempt = callTool($server, 'oc_update_product_stock', ['product_id' => 101, 'quantity' => 99]);
assert($resWriteAttempt['isError'] === true, 'Write attempt rejected in read-only mode');
assert(strpos($resWriteAttempt['content'][0]['text'], 'READ-ONLY') !== false, 'Error mentions read-only');
echo "  ✓ Write operations blocked in default READ-ONLY mode\n";

// Test 10: Authorized Mutation Mode
$writeServer = Factory::create([
    'db_host'         => '127.0.0.1',
    'db_user'         => 'root',
    'db_password'     => '',
    'db_name'         => 'oc_mcp_test',
    'db_prefix'       => 'oc_',
    'allow_mutations' => true,
]);

$resStockUpdate = callTool($writeServer, 'oc_update_product_stock', ['product_id' => 101, 'quantity' => 45]);
assert($resStockUpdate['isError'] === false, 'Stock update succeeded when permitted');
assert($resStockUpdate['structuredContent']['old_quantity'] === 15, 'Old quantity was 15');
assert($resStockUpdate['structuredContent']['new_quantity'] === 45, 'New quantity is 45');

$resPriceUpdate = callTool($writeServer, 'oc_update_product_price', ['product_id' => 101, 'price' => 899.99]);
assert($resPriceUpdate['isError'] === false, 'Price update succeeded');
assert($resPriceUpdate['structuredContent']['new_price'] === 899.99, 'New price is 899.99');

$resStatusUpdate = callTool($writeServer, 'oc_update_order_status', [
    'order_id'        => 501,
    'order_status_id' => 3,
    'comment'         => 'Dispatched via DHL Express',
    'notify'          => 1,
]);
assert($resStatusUpdate['isError'] === false, 'Order status update succeeded');
assert($resStatusUpdate['structuredContent']['new_status_name'] === 'Shipped', 'Status changed to Shipped');
echo "  ✓ Mutation mode (stock, price, order status) verified successfully\n";

echo "EndToEndToolsTest passed successfully! ✅\n";
