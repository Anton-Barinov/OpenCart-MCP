<?php
/**
 * OpenCart Universal MCP Server
 * Compatible with PHP 5.4 - 8.4+
 *
 * @author    Anton Barinov <https://github.com/Anton-Barinov>
 * @license   MIT
 */

namespace OpenCartMcp\Protocol;

class JsonRpcRequest
{
    /** @var string|int|null */
    public $id = null;

    /** @var string */
    public $method = '';

    /** @var array */
    public $params = [];

    /** @var string */
    public $jsonrpc = '2.0';

    /** @var bool */
    public $isNotification = false;

    /**
     * @param string|int|null $id
     * @param string $method
     * @param array $params
     * @param string $jsonrpc
     */
    public function __construct($id, $method, array $params = [], $jsonrpc = '2.0')
    {
        $this->id = $id;
        $this->method = $method;
        $this->params = $params;
        $this->jsonrpc = $jsonrpc;
        $this->isNotification = ($id === null);
    }

    /**
     * Parse raw JSON string into JsonRpcRequest instance or throw exception
     *
     * @param array $data Decoded JSON associative array
     * @return JsonRpcRequest
     * @throws \InvalidArgumentException
     */
    public static function fromArray(array $data)
    {
        if (!isset($data['jsonrpc']) || $data['jsonrpc'] !== '2.0') {
            throw new \InvalidArgumentException('Invalid JSON-RPC protocol version: expected "2.0"', -32600);
        }

        if (!isset($data['method']) || !is_string($data['method']) || $data['method'] === '') {
            throw new \InvalidArgumentException('Missing or invalid "method" property', -32600);
        }

        $id = isset($data['id']) ? $data['id'] : null;
        $params = isset($data['params']) && is_array($data['params']) ? $data['params'] : [];

        return new self($id, $data['method'], $params, $data['jsonrpc']);
    }
}
