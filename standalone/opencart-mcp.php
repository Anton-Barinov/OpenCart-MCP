<?php
/**
 * OpenCart Universal MCP — Standalone Drop-in Web & CLI Bridge
 * Compatible with OpenCart 2.0 - 4.0+ and PHP 5.4 - 8.4+
 *
 * @author    Anton Barinov <https://github.com/Anton-Barinov>
 * @license   MIT
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

// Locate OpenCart config.php in current or parent directory
$root = __DIR__;
if (!file_exists($root . '/config.php') && file_exists(dirname($root) . '/config.php')) {
    $root = dirname($root);
}

// Check if vendor autoloader exists or use src/
if (file_exists(__DIR__ . '/../src/autoload.php')) {
    require_once __DIR__ . '/../src/autoload.php';
} elseif (file_exists($root . '/src/autoload.php')) {
    require_once $root . '/src/autoload.php';
}

$isCli = (php_sapi_name() === 'cli');

// Check token from environment or GET/POST parameter if configured
$token = getenv('OPENCART_MCP_TOKEN') ? getenv('OPENCART_MCP_TOKEN') : '';
$allowMutations = (bool)getenv('OPENCART_MCP_ALLOW_MUTATIONS');

try {
    $server = \OpenCartMcp\Factory::create([
        'root'            => $root,
        'allow_mutations' => $allowMutations,
        'token'           => $token,
    ]);
} catch (\Exception $e) {
    if ($isCli) {
        fwrite(STDERR, "OpenCart-MCP Initialization Error: " . $e->getMessage() . "\n");
        exit(1);
    } else {
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: application/json');
        echo json_encode(['error' => $e->getMessage()]);
        exit;
    }
}

if ($isCli) {
    $transport = new \OpenCartMcp\Transport\StdioTransport($server);
    $transport->listen();
} else {
    $http = new \OpenCartMcp\Transport\HttpTransport($server, new \OpenCartMcp\Core\SecurityPolicy([
        'token'          => $token,
        'allowMutations' => $allowMutations,
    ]));
    $http->handleWebRequest();
}
