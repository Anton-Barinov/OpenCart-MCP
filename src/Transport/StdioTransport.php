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

class StdioTransport
{
    /** @var McpServer */
    private $server;

    /** @var resource */
    private $input;

    /** @var resource */
    private $output;

    /**
     * @param McpServer $server
     * @param resource|null $input
     * @param resource|null $output
     */
    public function __construct($server, $input = null, $output = null)
    {
        $this->server = $server;
        $this->input = $input ? $input : (defined('STDIN') ? STDIN : fopen('php://stdin', 'r'));
        $this->output = $output ? $output : (defined('STDOUT') ? STDOUT : fopen('php://stdout', 'w'));
    }

    /**
     * Run the stdio loop until EOF
     */
    public function listen()
    {
        while (!feof($this->input)) {
            $line = fgets($this->input);
            if ($line === false) {
                break;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $responseJson = $this->server->handleRaw($line);
            if ($responseJson !== null && $responseJson !== '') {
                fwrite($this->output, $responseJson . "\n");
                fflush($this->output);
            }
        }
    }
}
