<?php
/**
 * OpenCart Universal MCP Server
 * PHP 5.4 Compliance & Heavy Catalog Pagination Stress Test
 *
 * @author    Anton Barinov <https://github.com/Anton-Barinov>
 * @license   MIT
 */

namespace OpenCartMcp\Tests;

require_once __DIR__ . '/../src/autoload.php';

use OpenCartMcp\Core\Database;
use OpenCartMcp\Core\SecurityPolicy;
use OpenCartMcp\Core\SchemaAdapter;
use OpenCartMcp\Tools\ProductTools;

echo "=== Running ComplianceAndStressTest ===\n";

$pass = 0;
$fail = 0;

function assertCheck($name, $condition, $detail = '') {
    global $pass, $fail;
    if ($condition) {
        echo "  ✓ {$name}" . ($detail ? " [{$detail}]" : "") . "\n";
        $pass++;
    } else {
        echo "  ✗ FAIL: {$name}" . ($detail ? " [{$detail}]" : "") . "\n";
        $fail++;
    }
}

// ---------------------------------------------------------
// 1. PHP 5.4 Syntax & Token Compliance Scanner
// ---------------------------------------------------------
echo "1. Scanning all src/*.php files for strict PHP 5.4 compatibility...\n";

$srcDir = dirname(__DIR__) . '/src';
$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcDir));
$phpFiles = [];

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $phpFiles[] = $file->getPathname();
    }
}

assertCheck("Found PHP source files to audit", count($phpFiles) >= 8, count($phpFiles) . ' files');

$forbiddenTokens = [
    'T_COALESCE' => '?? (Null coalescing operator, PHP 7.0+)',
    'T_SPACESHIP' => '<=> (Spaceship operator, PHP 7.0+)',
    'T_FN' => 'fn() => (Arrow function, PHP 7.4+)',
    'T_MATCH' => 'match() (Match expression, PHP 8.0+)',
    'T_ATTRIBUTE' => '#[Attribute] (PHP 8.0+)',
    'T_READONLY' => 'readonly (PHP 8.1+)',
    'T_ENUM' => 'enum (PHP 8.1+)',
];

$syntaxViolations = [];

foreach ($phpFiles as $filePath) {
    $code = file_get_contents($filePath);
    $tokens = token_get_all($code);

    foreach ($tokens as $token) {
        if (is_array($token)) {
            $tokenName = token_name($token[0]);
            if (isset($forbiddenTokens[$tokenName])) {
                $syntaxViolations[] = basename($filePath) . " line " . $token[2] . ": " . $forbiddenTokens[$tokenName];
            }
        }
    }
}

assertCheck(
    "PHP 5.4 Token Compliance (zero PHP 7.x/8.x syntax tokens in src/)",
    empty($syntaxViolations),
    empty($syntaxViolations) ? "100% PHP 5.4 compatible" : implode('; ', $syntaxViolations)
);

// ---------------------------------------------------------
// 2. Heavy Catalog Pagination Stress & Clamp Test
// ---------------------------------------------------------
echo "2. Testing Pagination Clamping on Heavy Catalog Requests...\n";

$pdo = new \PDO('sqlite::memory:');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE oc_product (product_id INTEGER PRIMARY KEY, model TEXT, sku TEXT, quantity INTEGER, price REAL, status INTEGER, image TEXT, date_added TEXT);");
$pdo->exec("CREATE TABLE oc_product_description (product_id INTEGER, language_id INTEGER, name TEXT);");
$pdo->exec("CREATE TABLE oc_product_to_category (product_id INTEGER, category_id INTEGER);");
$pdo->exec("CREATE TABLE oc_language (language_id INTEGER PRIMARY KEY, code TEXT, status INTEGER);");
$pdo->exec("INSERT INTO oc_language VALUES (1, 'en-gb', 1);");

$db = new Database(['pdo' => $pdo, 'prefix' => 'oc_']);
$schema = new SchemaAdapter($db, '3.0.3.8', 'oc_');
$security = new SecurityPolicy(['mode' => 'read-only']);
$tools = new ProductTools($db, $schema, $security);

// Request an excessive limit of 1,000,000 items
$excessiveResult = $tools->listProducts(['limit' => 1000000, 'offset' => 0]);
assertCheck(
    "Excessive limit is clamped to <= 100",
    $excessiveResult['limit'] <= 100,
    "Requested 1,000,000, clamped to: " . $excessiveResult['limit']
);

// Request a negative limit
$negativeResult = $tools->listProducts(['limit' => -50, 'offset' => -10]);
assertCheck(
    "Negative limit and offset normalized safely",
    $negativeResult['limit'] >= 1 && $negativeResult['offset'] >= 0,
    "Limit: " . $negativeResult['limit'] . ", Offset: " . $negativeResult['offset']
);

echo "\n---------------------------------------------------------\n";
echo "Results: {$pass} Passed, {$fail} Failed\n";
echo "---------------------------------------------------------\n";

if ($fail > 0) {
    exit(1);
}

echo "ComplianceAndStressTest passed successfully! ✅\n";
exit(0);
