<?php
/**
 * OpenCart Universal MCP Server
 * Tests: Protocol Compliance & Output Buffer Isolation
 */

require_once __DIR__ . '/../src/autoload.php';

use OpenCartMcp\Protocol\McpServer;

echo "=== Running ProtocolTest ===\n";

$server = new McpServer('Test-OpenCart-MCP', '1.0.0');

// Register a noisy tool that prints debug text to stdout
$server->registerTool(
    'debug_noisy_tool',
    'A tool that simulates accidental var_dump and echo in legacy OpenCart code',
    [
        'type' => 'object',
        'properties' => ['text' => ['type' => 'string']],
    ],
    function (array $args) {
        echo "LEAKED_PRINT_FROM_LEGACY_CONTROLLER\n";
        var_dump(['stray_debug' => 123]);
        return ['status' => 'success', 'echo' => isset($args['text']) ? $args['text'] : ''];
    }
);

// 1. Test initialize
$initReq = json_encode([
    'jsonrpc' => '2.0',
    'id'      => 1,
    'method'  => 'initialize',
    'params'  => ['protocolVersion' => '2024-11-05'],
]);
$initRes = json_decode($server->handleRaw($initReq), true);
assert($initRes['result']['protocolVersion'] === '2024-11-05', 'Protocol version matches');
assert($initRes['result']['serverInfo']['name'] === 'Test-OpenCart-MCP', 'Server name matches');
echo "  ✓ MCP initialize handshake passed\n";

// 2. Test ping
$pingReq = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']);
$pingRes = json_decode($server->handleRaw($pingReq), true);
assert(isset($pingRes['result']), 'Ping returns result');
assert($pingRes['id'] === 2, 'Ping matches id');
echo "  ✓ MCP ping passed\n";

// 3. Test tools/list
$listReq = json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list']);
$listRes = json_decode($server->handleRaw($listReq), true);
assert(count($listRes['result']['tools']) >= 1, 'Tool registered');
assert($listRes['result']['tools'][0]['name'] === 'debug_noisy_tool', 'Tool name matches');
echo "  ✓ tools/list passed\n";

// 4. Test tools/call with Output Buffer Isolation
ob_start();
$callReq = json_encode([
    'jsonrpc' => '2.0',
    'id'      => 4,
    'method'  => 'tools/call',
    'params'  => [
        'name'      => 'debug_noisy_tool',
        'arguments' => ['text' => 'clean_value'],
    ],
]);
$callResRaw = $server->handleRaw($callReq);
$stdoutLeaked = ob_get_clean();

assert(empty($stdoutLeaked), "Zero bytes leaked directly to STDOUT during noisy tool call");
$callRes = json_decode($callResRaw, true);
assert($callRes !== null, 'Response is 100% valid JSON');
assert($callRes['result']['isError'] === false, 'Tool succeeded');
assert($callRes['result']['structuredContent']['status'] === 'success', 'Structured content returned');
echo "  ✓ tools/call with strict STDOUT isolation passed\n";

// 5. Test Empty Batch []
$emptyBatchRes = json_decode($server->handleRaw('[]'), true);
assert($emptyBatchRes['error']['code'] === -32600, 'Empty batch [] returns error code -32600');
echo "  ✓ Empty batch [] -32600 handling passed\n";

// 6. Test Malformed JSON
$badJsonRes = json_decode($server->handleRaw('{"jsonrpc": "2.0", broken...'), true);
assert($badJsonRes['error']['code'] === -32700, 'Malformed JSON returns -32700');
echo "  ✓ Parse error -32700 handling passed\n";

echo "ProtocolTest passed successfully! ✅\n";
