<?php
/**
 * OpenCart Universal MCP Server
 * Tests: Real Site Config Parsing & Detection Audit
 */

require_once __DIR__ . '/../src/autoload.php';

use OpenCartMcp\Core\OpenCartDetector;

echo "=== Running RealSitesAuditTest ===\n";

$candidates = [
    '/Users/bps/sites/prostore1.opencart-cms.ru',
    '/Users/bps/sites/prostore2.opencart-cms.ru',
    '/Users/bps/sites/prostore3.opencart-cms.ru',
    '/Users/bps/sites/bazarCMS',
    '/Users/bps/sites/proclimate.by',
    '/Users/bps/sites/technoprom.kz',
];

$checkedCount = 0;

foreach ($candidates as $sitePath) {
    if (!file_exists($sitePath . '/config.php')) {
        continue;
    }

    $detector = new OpenCartDetector($sitePath);
    $version = $detector->getVersion();
    $major = $detector->getMajorVersion();
    $dbName = $detector->getDbDatabase();
    $prefix = $detector->getDbPrefix();

    assert(!empty($dbName), "Database name extracted for {$sitePath}");
    assert(!empty($prefix), "Table prefix extracted for {$sitePath}");

    echo "  ✓ Tested " . basename($sitePath) . ": OC Version [{$version} / {$major}], DB: [{$dbName}], Prefix: [{$prefix}]\n";
    $checkedCount++;
}

assert($checkedCount >= 3, "At least 3 real demo sites detected and parsed");
echo "RealSitesAuditTest passed successfully! Verified {$checkedCount} live store configurations! ✅\n";
