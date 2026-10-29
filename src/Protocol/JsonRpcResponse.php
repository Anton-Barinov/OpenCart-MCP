<?php
/**
 * OpenCart Universal MCP Server
 * Compatible with PHP 5.4 - 8.4+
 *
 * @author    Anton Barinov <https://github.com/Anton-Barinov>
 * @license   MIT
 */

namespace OpenCartMcp\Protocol;

class JsonRpcResponse
{
    /** @var string|int|null */
    public $id;

    /** @var mixed */
    public $result;

    /** @var array|null */
    public $error;

    /** @var string */
    public $jsonrpc = '2.0';

    /**
     * @param string|int|null $id
     * @param mixed $result
     * @param array|null $error
     */
    public function __construct($id, $result = null, $error = null)
    {
        $this->id = $id;
        $this->result = $result;
        $this->error = $error;
    }

    public static function success($id, $result)
    {
        return new self($id, $result, null);
    }

    public static function error($id, $code, $message, $data = null)
    {
        $err = [
            'code'    => (int)$code,
            'message' => (string)$message,
        ];
        if ($data !== null) {
            $err['data'] = $data;
        }
        return new self($id, null, $err);
    }

    public function toArray()
    {
        $res = [
            'jsonrpc' => '2.0',
            'id'      => $this->id,
        ];

        if ($this->error !== null) {
            $res['error'] = $this->error;
        } else {
            $res['result'] = $this->result;
        }

        return $res;
    }

    public function toJson()
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
