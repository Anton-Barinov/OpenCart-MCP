<?php
/**
 * OpenCart Universal MCP Server
 * Compatible with PHP 5.4 - 8.4+
 *
 * @author    Anton Barinov <https://github.com/Anton-Barinov>
 * @license   MIT
 */

namespace OpenCartMcp\Transport;

use OpenCartMcp\Protocol\McpServer;
use OpenCartMcp\Core\SecurityPolicy;

class HttpTransport
{
    /** @var McpServer */
    private $server;

    /** @var SecurityPolicy */
    private $security;

    /**
     * @param McpServer $server
     * @param SecurityPolicy|null $security
     */
    public function __construct($server, $security = null)
    {
        $this->server = $server;
        $this->security = ($security !== null) ? $security : new SecurityPolicy();
    }

    /**
     * Handle incoming web request (e.g. from index.php or webhook)
     */
    public function handleWebRequest()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';

        // 1. Handle CORS pre-flight
        if ($method === 'OPTIONS') {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
            header('Access-Control-Max-Age: 86400');
            header('HTTP/1.1 204 No Content');
            exit;
        }

        // 2. Enforce IP Whitelist
        if (!$this->security->isIpAllowed($ip)) {
            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Forbidden: Client IP not in whitelist']);
            exit;
        }

        // 3. Enforce Bearer Token Authentication
        $authHeader = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['Authorization'])) {
                $authHeader = $headers['Authorization'];
            }
        }

        if (!$this->security->validateToken($authHeader)) {
            header('HTTP/1.1 401 Unauthorized');
            header('WWW-Authenticate: Bearer realm="OpenCart-MCP"');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Unauthorized: Invalid or missing Bearer token']);
            exit;
        }

        // 4. Method validation
        if ($method !== 'POST') {
            header('HTTP/1.1 405 Method Not Allowed');
            header('Allow: POST, OPTIONS');
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Method Not Allowed: MCP expects POST for JSON-RPC payloads']);
            exit;
        }

        // 5. Read payload and execute
        $rawInput = file_get_contents('php://input');
        $response = $this->server->handleRaw($rawInput);

        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');

        if ($response !== null) {
            echo $response;
        } else {
            header('HTTP/1.1 202 Accepted');
        }
    }

    /**
     * Process raw JSON payload directly (useful in unit tests or framework controllers)
     *
     * @param string $rawJson
     * @param string $authHeader
     * @param string $clientIp
     * @return array [statusCode, headers, body]
     */
    public function handleRawHttp($rawJson, $authHeader = '', $clientIp = '127.0.0.1')
    {
        if (!$this->security->isIpAllowed($clientIp)) {
            return [403, ['Content-Type' => 'application/json'], json_encode(['error' => 'Forbidden: IP blocked'])];
        }

        if (!$this->security->validateToken($authHeader)) {
            return [401, ['Content-Type' => 'application/json'], json_encode(['error' => 'Unauthorized'])];
        }

        $res = $this->server->handleRaw($rawJson);
        return [200, ['Content-Type' => 'application/json'], $res ? $res : ''];
    }

    /**
     * Format an SSE keep-alive heartbeat comment
     *
     * @return string
     */
    public static function formatKeepAlive()
    {
        return ": keep-alive\n\n";
    }
}
