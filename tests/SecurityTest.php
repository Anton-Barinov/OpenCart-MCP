<?php
/**
 * OpenCart Universal MCP Server
 * Tests: SecurityPolicy & Safe Sanitization
 */

require_once __DIR__ . '/../src/autoload.php';

use OpenCartMcp\Core\SecurityPolicy;

echo "=== Running SecurityTest ===\n";

$security = new SecurityPolicy([
    'maskPii'        => true,
    'allowMutations' => false,
    'token'          => 'secret_mcp_token_123',
    'ipWhitelist'    => ['127.0.0.1', '192.168.1.50'],
]);

// 1. Test PII Masking
$maskedEmail = $security->maskEmail('customer@gmail.com');
assert($maskedEmail === 'cu***@gmail.com', "Email masked properly: got {$maskedEmail}");

$maskedPhone = $security->maskPhone('+7 (999) 123-45-67');
assert(strpos($maskedPhone, '***') !== false, "Phone masked properly: got {$maskedPhone}");

$maskedName = $security->maskName('Александр');
assert($maskedName === 'А***', "Cyrillic Name masked properly: got {$maskedName}");

$maskedIp = $security->maskIp('192.168.1.105');
assert($maskedIp === '192.168.*.*', "IP masked properly: got {$maskedIp}");

$customerData = [
    'customer_id' => 15,
    'firstname'   => 'John',
    'lastname'    => 'Doe',
    'email'       => 'john.doe@example.com',
    'telephone'   => '1234567890',
    'password'    => 'f2d81a260dea8a100dd517984e53c56a75acacdc',
    'salt'        => 's0m3s4lt',
    'token'       => 'temp_reset_token',
];
$sanitized = $security->sanitizeCustomerRecord($customerData);

assert(!isset($sanitized['password']), 'Password must be completely stripped');
assert(!isset($sanitized['salt']), 'Salt must be completely stripped');
assert(!isset($sanitized['token']), 'Reset token must be completely stripped');
assert($sanitized['firstname'] === 'J***', 'Firstname masked');
assert($sanitized['lastname'] === 'D***', 'Lastname masked');
assert($sanitized['email'] === 'jo***@example.com', 'Email masked');
echo "  ✓ PII masking and password stripping passed\n";

// 2. Test Bearer Token Auth
assert($security->validateToken('Bearer secret_mcp_token_123') === true, 'Valid Bearer token accepted');
assert($security->validateToken('secret_mcp_token_123') === true, 'Raw matching token accepted');
assert($security->validateToken('Bearer wrong_token') === false, 'Invalid token rejected');
assert($security->validateToken('') === false, 'Empty token rejected');
echo "  ✓ Bearer token authentication passed\n";

// 3. Test IP Whitelist
assert($security->isIpAllowed('127.0.0.1') === true, 'Localhost IP allowed');
assert($security->isIpAllowed('192.168.1.50') === true, 'Whitelisted IP allowed');
assert($security->isIpAllowed('10.0.0.1') === false, 'Non-whitelisted IP blocked');
echo "  ✓ IP whitelist verification passed\n";

echo "SecurityTest passed successfully! ✅\n";
