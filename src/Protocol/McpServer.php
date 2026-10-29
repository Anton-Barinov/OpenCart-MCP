<?php
/**
 * OpenCart Universal MCP Server
 * Compatible with PHP 5.4 - 8.4+
 *
 * @author    Anton Barinov <https://github.com/Anton-Barinov>
 * @license   MIT
 */

namespace OpenCartMcp\Protocol;

class McpServer
{
    /** @var string */
    private $name;

    /** @var string */
    private $version;

    /** @var array */
    private $tools = [];

    /** @var array */
    private $resources = [];

    /** @var array */
    private $prompts = [];

    /** @var string */
    private $protocolVersion = '2024-11-05';

    /**
     * @param string $name
     * @param string $version
     */
    public function __construct($name = 'OpenCart-Universal-MCP', $version = '1.0.0')
    {
        $this->name = $name;
        $this->version = $version;
    }

    /**
     * Register an MCP tool
     *
     * @param string $name
     * @param string $description
     * @param array $inputSchema
     * @param callable $handler
     */
    public function registerTool($name, $description, array $inputSchema, $handler)
    {
        $this->tools[$name] = [
            'name'        => $name,
            'description' => $description,
            'inputSchema' => $inputSchema,
            'handler'     => $handler,
        ];
    }

    /**
     * Register an MCP resource
     *
     * @param string $uri
     * @param string $name
     * @param string $description
     * @param string $mimeType
     * @param callable $handler
     */
    public function registerResource($uri, $name, $description, $mimeType, $handler)
    {
        $this->resources[$uri] = [
            'uri'         => $uri,
            'name'        => $name,
            'description' => $description,
            'mimeType'    => $mimeType,
            'handler'     => $handler,
        ];
    }

    /**
     * Register an MCP prompt template
     *
     * @param string $name
     * @param string $description
     * @param array $arguments
     * @param callable $handler
     */
    public function registerPrompt($name, $description, array $arguments, $handler)
    {
        $this->prompts[$name] = [
            'name'        => $name,
            'description' => $description,
            'arguments'   => $arguments,
            'handler'     => $handler,
        ];
    }

    /**
     * Process raw JSON payload (single object or batch array)
     *
     * @param string $rawJson
     * @return string|null JSON-RPC response string, or null if all requests were notifications
     */
    public function handleRaw($rawJson)
    {
        $rawJson = trim($rawJson);
        if ($rawJson === '') {
            return JsonRpcResponse::error(null, -32700, "Parse error: Empty input")->toJson();
        }

        $decoded = @json_decode($rawJson, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return JsonRpcResponse::error(null, -32700, "Parse error: Invalid JSON syntax (" . json_last_error_msg() . ")")->toJson();
        }

        // Handle empty batch: []
        if (is_array($decoded) && empty($decoded)) {
            return JsonRpcResponse::error(null, -32600, "Invalid Request: Batch array cannot be empty")->toJson();
        }

        // Batch processing
        if (is_array($decoded) && isset($decoded[0])) {
            $responses = [];
            foreach ($decoded as $item) {
                if (!is_array($item)) {
                    $responses[] = JsonRpcResponse::error(null, -32600, "Invalid Request: Batch element must be an object")->toArray();
                    continue;
                }
                $res = $this->handleItem($item);
                if ($res !== null) {
                    $responses[] = $res->toArray();
                }
            }
            return !empty($responses) ? json_encode($responses, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        }

        // Single request
        if (!is_array($decoded)) {
            return JsonRpcResponse::error(null, -32600, "Invalid Request: Expected JSON object")->toJson();
        }

        $resp = $this->handleItem($decoded);
        return $resp !== null ? $resp->toJson() : null;
    }

    /**
     * Handle single decoded request item
     *
     * @param array $item
     * @return JsonRpcResponse|null
     */
    public function handleItem(array $item)
    {
        try {
            $request = JsonRpcRequest::fromArray($item);
        } catch (\InvalidArgumentException $e) {
            $id = isset($item['id']) ? $item['id'] : null;
            return JsonRpcResponse::error($id, $e->getCode() ?: -32600, $e->getMessage());
        }

        return $this->handleRequest($request);
    }

    /**
     * Handle structured JsonRpcRequest
     *
     * @param JsonRpcRequest $request
     * @return JsonRpcResponse|null
     */
    public function handleRequest(JsonRpcRequest $request)
    {
        // Notifications require no response
        if ($request->isNotification) {
            if ($request->method === 'notifications/initialized') {
                return null;
            }
            return null;
        }

        switch ($request->method) {
            case 'initialize':
                return JsonRpcResponse::success($request->id, [
                    'protocolVersion' => $this->protocolVersion,
                    'serverInfo'      => [
                        'name'    => $this->name,
                        'version' => $this->version,
                    ],
                    'capabilities'    => [
                        'tools'     => ['listChanged' => false],
                        'resources' => ['subscribe' => false, 'listChanged' => false],
                        'prompts'   => ['listChanged' => false],
                    ],
                ]);

            case 'ping':
                return JsonRpcResponse::success($request->id, new \stdClass());

            case 'tools/list':
                $toolList = [];
                foreach ($this->tools as $t) {
                    $toolList[] = [
                        'name'        => $t['name'],
                        'description' => $t['description'],
                        'inputSchema' => $t['inputSchema'],
                    ];
                }
                return JsonRpcResponse::success($request->id, ['tools' => $toolList]);

            case 'tools/call':
                $toolName = isset($request->params['name']) ? $request->params['name'] : '';
                $arguments = isset($request->params['arguments']) && is_array($request->params['arguments'])
                    ? $request->params['arguments']
                    : [];

                if (!isset($this->tools[$toolName])) {
                    return JsonRpcResponse::error($request->id, -32601, "Tool not found: {$toolName}");
                }

                return $this->executeTool($request->id, $this->tools[$toolName], $arguments);

            case 'resources/list':
                $resList = [];
                foreach ($this->resources as $r) {
                    $resList[] = [
                        'uri'         => $r['uri'],
                        'name'        => $r['name'],
                        'description' => $r['description'],
                        'mimeType'    => $r['mimeType'],
                    ];
                }
                return JsonRpcResponse::success($request->id, ['resources' => $resList]);

            case 'resources/read':
                $uri = isset($request->params['uri']) ? $request->params['uri'] : '';
                if (!isset($this->resources[$uri])) {
                    return JsonRpcResponse::error($request->id, -32002, "Resource not found: {$uri}");
                }

                $handler = $this->resources[$uri]['handler'];
                try {
                    $content = call_user_func($handler, $uri);
                    return JsonRpcResponse::success($request->id, [
                        'contents' => [
                            [
                                'uri'      => $uri,
                                'mimeType' => $this->resources[$uri]['mimeType'],
                                'text'     => is_string($content) ? $content : json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                            ]
                        ]
                    ]);
                } catch (\Exception $e) {
                    return JsonRpcResponse::error($request->id, -32603, "Resource read error: " . $e->getMessage());
                }

            case 'prompts/list':
                $promptList = [];
                foreach ($this->prompts as $p) {
                    $promptList[] = [
                        'name'        => $p['name'],
                        'description' => $p['description'],
                        'arguments'   => $p['arguments'],
                    ];
                }
                return JsonRpcResponse::success($request->id, ['prompts' => $promptList]);

            case 'prompts/get':
                $promptName = isset($request->params['name']) ? $request->params['name'] : '';
                $arguments = isset($request->params['arguments']) && is_array($request->params['arguments'])
                    ? $request->params['arguments']
                    : [];

                if (!isset($this->prompts[$promptName])) {
                    return JsonRpcResponse::error($request->id, -32601, "Prompt not found: {$promptName}");
                }

                $handler = $this->prompts[$promptName]['handler'];
                try {
                    $messages = call_user_func($handler, $arguments);
                    return JsonRpcResponse::success($request->id, [
                        'description' => $this->prompts[$promptName]['description'],
                        'messages'    => $messages,
                    ]);
                } catch (\Exception $e) {
                    return JsonRpcResponse::error($request->id, -32603, "Prompt error: " . $e->getMessage());
                }

            default:
                return JsonRpcResponse::error($request->id, -32601, "Method not found: {$request->method}");
        }
    }

    /**
     * Execute tool handler with strict output buffer isolation
     *
     * @param string|int $id
     * @param array $tool
     * @param array $args
     * @return JsonRpcResponse
     */
    private function executeTool($id, array $tool, array $args)
    {
        // Trap stray echo / var_dump to protect JSON-RPC wire on STDOUT
        ob_start();
        $isError = false;
        $errorMsg = '';
        $resultData = null;

        try {
            $handler = $tool['handler'];
            $resultData = call_user_func($handler, $args);
        } catch (\Exception $e) {
            $isError = true;
            $errorMsg = $e->getMessage();
        }

        $leaked = ob_get_clean();
        if ($leaked !== false && $leaked !== '') {
            // Forward leaked output to STDERR safely
            fwrite(STDERR, "[OpenCart-MCP Tool '{$tool['name']}' Warning/Debug]: " . $leaked . "\n");
        }

        if ($isError) {
            return JsonRpcResponse::success($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => "Error executing tool '{$tool['name']}': {$errorMsg}",
                    ]
                ],
                'isError' => true,
            ]);
        }

        $textResponse = is_string($resultData)
            ? $resultData
            : json_encode($resultData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return JsonRpcResponse::success($id, [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $textResponse,
                ]
            ],
            'structuredContent' => is_array($resultData) ? $resultData : null,
            'isError'           => false,
        ]);
    }

    public function getTools()
    {
        return $this->tools;
    }

    public function getResources()
    {
        return $this->resources;
    }

    public function getPrompts()
    {
        return $this->prompts;
    }
}
