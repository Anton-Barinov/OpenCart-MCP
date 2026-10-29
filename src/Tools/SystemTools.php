<?php
/**
 * OpenCart Universal MCP Server
 * Compatible with PHP 5.4 - 8.4+
 *
 * @author    Anton Barinov <https://github.com/Anton-Barinov>
 * @license   MIT
 */

namespace OpenCartMcp\Tools;

use OpenCartMcp\Core\Database;
use OpenCartMcp\Core\SchemaAdapter;
use OpenCartMcp\Core\OpenCartDetector;

class SystemTools
{
    /** @var Database */
    private $db;

    /** @var SchemaAdapter */
    private $schema;

    /** @var OpenCartDetector */
    private $detector;

    public function __construct(Database $db, SchemaAdapter $schema, OpenCartDetector $detector)
    {
        $this->db = $db;
        $this->schema = $schema;
        $this->detector = $detector;
    }

    /**
     * Get OpenCart environment, versions and database stats
     *
     * @param array $args
     * @return array
     */
    public function systemInfo(array $args = [])
    {
        $storeName = $this->schema->getSetting('config', 'config_name');
        if (!$storeName) {
            $storeName = 'OpenCart Store';
        }

        $totalProducts = (int)$this->db->queryScalar("SELECT COUNT(*) FROM {prefix}product");
        $totalOrders = (int)$this->db->queryScalar("SELECT COUNT(*) FROM {prefix}order");
        $totalCustomers = (int)$this->db->queryScalar("SELECT COUNT(*) FROM {prefix}customer");

        return [
            'store_name'       => $storeName,
            'opencart_version' => $this->detector->getVersion(),
            'opencart_major'   => $this->detector->getMajorVersion(),
            'php_version'      => PHP_VERSION,
            'os'               => PHP_OS,
            'database'         => [
                'driver'    => $this->detector->getDbDriver(),
                'prefix'    => $this->db->getPrefix(),
                'read_only' => $this->db->isReadOnly(),
            ],
            'statistics'       => [
                'total_products'  => $totalProducts,
                'total_orders'    => $totalOrders,
                'total_customers' => $totalCustomers,
            ],
            'paths'            => [
                'root'    => $this->detector->getRootPath(),
                'storage' => $this->detector->getStoragePath(),
            ],
        ];
    }

    /**
     * Read the last N lines of OpenCart error log safely
     *
     * @param array $args
     * @return array
     */
    public function readErrorLog(array $args)
    {
        $linesToRead = isset($args['lines']) ? min(500, max(5, (int)$args['lines'])) : 50;

        $logDir = $this->detector->getLogPath();
        $logFile = $logDir . 'error.log';

        if (!file_exists($logFile)) {
            // Check alternative locations
            $alt = $this->detector->getRootPath() . '/system/logs/error.log';
            if (file_exists($alt)) {
                $logFile = $alt;
            } else {
                return [
                    'found'   => false,
                    'message' => 'No error.log found at ' . $logFile,
                    'lines'   => [],
                ];
            }
        }

        $content = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($content === false) {
            return [
                'found'   => true,
                'message' => 'Unable to read ' . $logFile,
                'lines'   => [],
            ];
        }

        $totalLines = count($content);
        $slice = array_slice($content, -$linesToRead);

        return [
            'found'       => true,
            'file'        => basename($logFile),
            'total_lines' => $totalLines,
            'lines_read'  => count($slice),
            'lines'       => $slice,
        ];
    }

    /**
     * List all installed OCMOD modifications
     *
     * @param array $args
     * @return array
     */
    public function listModifications(array $args = [])
    {
        try {
            $rows = $this->db->query(
                "SELECT modification_id, name, code, author, version, status, date_added
                 FROM {prefix}modification
                 ORDER BY name ASC"
            );

            foreach ($rows as &$r) {
                $r['modification_id'] = (int)$r['modification_id'];
                $r['status'] = (int)$r['status'] === 1 ? 'Enabled' : 'Disabled';
            }

            return [
                'count'         => count($rows),
                'modifications' => $rows,
            ];
        } catch (\Exception $e) {
            return [
                'count'         => 0,
                'modifications' => [],
                'message'       => 'Modifications table not found: ' . $e->getMessage(),
            ];
        }
    }
}
