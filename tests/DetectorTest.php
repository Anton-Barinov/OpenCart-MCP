<?php
/**
 * OpenCart Universal MCP Server
 * Tests: Detector & Version Sniffing
 */

require_once __DIR__ . '/../src/autoload.php';

use OpenCartMcp\Core\OpenCartDetector;

echo "=== Running DetectorTest ===\n";

$tmpDir = sys_get_temp_dir() . '/oc_detector_test_' . uniqid();
mkdir($tmpDir . '/catalog', 0755, true);
mkdir($tmpDir . '/system', 0755, true);

// 1. Write mock OpenCart 3 config.php
$configContent = <<<'PHP'
<?php
define('HTTP_SERVER', 'http://localhost/');
define('DIR_APPLICATION', '/var/www/catalog/');
define('DIR_SYSTEM', '/var/www/system/');
define('DIR_STORAGE', '/var/www/system/storage/');
define('DIR_IMAGE', '/var/www/image/');
define('DB_DRIVER', 'mysqli');
define('DB_HOSTNAME', '127.0.0.1');
define('DB_USERNAME', 'oc_user');
define('DB_PASSWORD', 'secret123');
define('DB_DATABASE', 'shop_db');
define('DB_PORT', '3306');
define('DB_PREFIX', 'oc_');
PHP;

file_put_contents($tmpDir . '/config.php', $configContent);
file_put_contents($tmpDir . '/index.php', "<?php define('VERSION', '3.0.3.8');");

$detector = new OpenCartDetector($tmpDir);

assert($detector->getVersion() === '3.0.3.8', 'Version should be 3.0.3.8');
assert($detector->getMajorVersion() === '3.x', 'Major version should be 3.x');
assert($detector->getDbHostname() === '127.0.0.1', 'DB Host matches');
assert($detector->getDbUsername() === 'oc_user', 'DB User matches');
assert($detector->getDbDatabase() === 'shop_db', 'DB Name matches');
assert($detector->getDbPrefix() === 'oc_', 'DB Prefix matches');
assert($detector->getStoragePath() === '/var/www/system/storage/', 'Storage path matches');
echo "  ✓ OpenCart 3.x config detection passed\n";

// 2. Test OpenCart 2.3 detection
file_put_contents($tmpDir . '/index.php', "<?php define('VERSION', '2.3.0.2');");
$detector2 = new OpenCartDetector($tmpDir);
assert($detector2->getVersion() === '2.3.0.2', 'Version should be 2.3.0.2');
assert($detector2->getMajorVersion() === '2.x', 'Major version should be 2.x');
echo "  ✓ OpenCart 2.x version detection passed\n";

// 3. Test OpenCart 4.0 detection
file_put_contents($tmpDir . '/index.php', "<?php define('VERSION', '4.0.2.3');");
$detector4 = new OpenCartDetector($tmpDir);
assert($detector4->getVersion() === '4.0.2.3', 'Version should be 4.0.2.3');
assert($detector4->getMajorVersion() === '4.x', 'Major version should be 4.x');
echo "  ✓ OpenCart 4.x version detection passed\n";

// 4. Test ocStore distribution detection
file_put_contents($tmpDir . '/index.php', "<?php define('VERSION', '3.0.3.8-ce.1.1');");
$detectorOcStore = new OpenCartDetector($tmpDir);
assert($detectorOcStore->getDistribution() === 'ocStore', 'Distribution should be ocStore');
echo "  ✓ ocStore distribution detection passed (3.0.3.8-ce.1.1)\n";

// 5. Test OpenCart 1.5.x legacy detection
file_put_contents($tmpDir . '/index.php', "<?php define('VERSION', '1.5.6.4');");
$detector15 = new OpenCartDetector($tmpDir);
assert($detector15->getMajorVersion() === '1.5.x', 'Major version should be 1.5.x');
echo "  ✓ OpenCart 1.5 legacy version detection passed (1.5.6.4)\n";

// Cleanup
@unlink($tmpDir . '/config.php');
@unlink($tmpDir . '/index.php');
@rmdir($tmpDir . '/catalog');
@rmdir($tmpDir . '/system');
@rmdir($tmpDir);

echo "DetectorTest passed successfully! ✅\n";
