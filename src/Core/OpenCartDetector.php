<?php
/**
 * OpenCart Universal MCP Server
 * Compatible with PHP 5.4 - 8.4+
 *
 * @author    Anton Barinov <https://github.com/Anton-Barinov>
 * @license   MIT
 */

namespace OpenCartMcp\Core;

class OpenCartDetector
{
    /** @var string */
    private $rootPath;

    /** @var array */
    private $configData = [];

    /** @var string */
    private $version = 'unknown';

    /** @var string */
    private $majorVersion = 'unknown'; // '2.x', '3.x', '4.x'

    /**
     * @param string $rootPath Path to OpenCart root directory
     */
    public function __construct($rootPath = '')
    {
        if ($rootPath) {
            $this->setRootPath($rootPath);
        }
    }

    /**
     * Set and resolve root path
     *
     * @param string $path
     * @return bool
     */
    public function setRootPath($path)
    {
        $real = realpath($path);
        if (!$real || !is_dir($real)) {
            $real = rtrim($path, '/\\');
        }
        $this->rootPath = $real;
        $this->configData = [];
        $this->version = 'unknown';
        $this->majorVersion = 'unknown';

        if (file_exists($this->rootPath . '/config.php')) {
            $this->parseConfigFile($this->rootPath . '/config.php');
            $this->detectVersion();
            return true;
        }

        return false;
    }

    /**
     * Locate OpenCart root by traversing upwards or looking in candidate paths
     *
     * @param string $startPath
     * @return string|false
     */
    public static function findRoot($startPath = '')
    {
        if (!$startPath) {
            $startPath = getcwd();
        }

        $dir = realpath($startPath) ?: $startPath;
        while ($dir && $dir !== '/' && $dir !== '\\') {
            if (file_exists($dir . '/config.php') && file_exists($dir . '/index.php')) {
                // Verify it has OpenCart hallmarks (catalog, system)
                if (is_dir($dir . '/catalog') && is_dir($dir . '/system')) {
                    return $dir;
                }
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return false;
    }

    /**
     * Safely parse OpenCart config.php without executing arbitrary side effects
     *
     * @param string $file
     */
    public function parseConfigFile($file)
    {
        if (!file_exists($file)) {
            return;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return;
        }

        // Match all define('KEY', 'VALUE') or define("KEY", "VALUE")
        $pattern = "/define\s*\(\s*['\"]([^'\"]+)['\"]\s*,\s*['\"]([^'\"]*)['\"]\s*\)/i";
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $this->configData[$match[1]] = $match[2];
            }
        }

        // Also match boolean and integer definitions: define('KEY', 3306) or define('KEY', true)
        $numPattern = "/define\s*\(\s*['\"]([^'\"]+)['\"]\s*,\s*([0-9]+|true|false)\s*\)/i";
        if (preg_match_all($numPattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (!isset($this->configData[$match[1]])) {
                    $val = strtolower($match[2]);
                    if ($val === 'true') {
                        $this->configData[$match[1]] = true;
                    } elseif ($val === 'false') {
                        $this->configData[$match[1]] = false;
                    } else {
                        $this->configData[$match[1]] = $match[2];
                    }
                }
            }
        }
    }

    /**
     * Detect OpenCart version from index.php or directory structure
     */
    public function detectVersion()
    {
        $version = 'unknown';

        // 1. Check index.php
        $indexPath = $this->rootPath . '/index.php';
        if (file_exists($indexPath)) {
            $content = file_get_contents($indexPath);
            if ($content && preg_match("/define\s*\(\s*['\"]VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/i", $content, $m)) {
                $version = $m[1];
            }
        }

        // 2. Check admin/index.php if not found in catalog index.php
        if ($version === 'unknown') {
            $adminIndexPath = $this->rootPath . '/admin/index.php';
            if (file_exists($adminIndexPath)) {
                $content = file_get_contents($adminIndexPath);
                if ($content && preg_match("/define\s*\(\s*['\"]VERSION['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/i", $content, $m)) {
                    $version = $m[1];
                }
            }
        }

        $this->version = $version;

        if ($version !== 'unknown') {
            if (strpos($version, '1.5') === 0) {
                $this->majorVersion = '1.5.x';
            } elseif (strpos($version, '2.') === 0) {
                $this->majorVersion = '2.x';
            } elseif (strpos($version, '3.') === 0) {
                $this->majorVersion = '3.x';
            } elseif (strpos($version, '4.') === 0) {
                $this->majorVersion = '4.x';
            }
        } else {
            // Heuristic detection based on file structure
            if (is_dir($this->rootPath . '/extension') || file_exists($this->rootPath . '/system/framework.php')) {
                // OpenCart 4 has /extension/ directory in root or catalog/model/extension
                if (is_dir($this->rootPath . '/extension')) {
                    $this->majorVersion = '4.x';
                } else {
                    $this->majorVersion = '3.x';
                }
            } elseif (file_exists($this->rootPath . '/system/modification')) {
                $this->majorVersion = '2.x';
            } else {
                $this->majorVersion = '3.x'; // Default fallback
            }
        }
    }

    /**
     * Sniff database schema to confirm / refine version
     *
     * @param Database $db
     * @return string
     */
    public function sniffVersionFromDb($db)
    {
        $prefix = $this->getDbPrefix();

        // 4.x check: seo_url table with 'key' and 'value' columns, or extension_install table
        try {
            $res = $db->query("SHOW TABLES LIKE '" . $db->escape($prefix . "extension_install") . "'");
            if (!empty($res)) {
                $this->majorVersion = '4.x';
                return '4.x';
            }
        } catch (\Exception $e) {
            // Ignore
        }

        // 3.x check: seo_url table exists and information_to_store or translation
        try {
            $res = $db->query("SHOW TABLES LIKE '" . $db->escape($prefix . "seo_url") . "'");
            if (!empty($res)) {
                $this->majorVersion = '3.x';
                return '3.x';
            }
        } catch (\Exception $e) {
            // Ignore
        }

        // 2.x check: url_alias table exists
        try {
            $res = $db->query("SHOW TABLES LIKE '" . $db->escape($prefix . "url_alias") . "'");
            if (!empty($res)) {
                $this->majorVersion = '2.x';
                return '2.x';
            }
        } catch (\Exception $e) {
            // Ignore
        }

        return $this->majorVersion;
    }

    // --- Getters ---

    public function getRootPath()
    {
        return $this->rootPath;
    }

    public function getVersion()
    {
        return $this->version;
    }

    public function getMajorVersion()
    {
        return $this->majorVersion;
    }

    /**
     * Identify OpenCart distribution / edition (ocStore, OpenCart.pro, BazarCMS, Standard)
     *
     * @return string
     */
    public function getDistribution()
    {
        $v = strtolower($this->version);
        if (strpos($v, 'ce') !== false || strpos($v, 'ocstore') !== false) {
            return 'ocStore';
        }
        if (strpos($v, 'pro') !== false) {
            return 'OpenCart.pro';
        }
        if (strpos(strtolower($this->getDbPrefix()), 'bazar') !== false) {
            return 'BazarCMS';
        }
        return 'OpenCart Standard';
    }

    public function getDbDriver()
    {
        return isset($this->configData['DB_DRIVER']) ? $this->configData['DB_DRIVER'] : 'mysqli';
    }

    public function getDbHostname()
    {
        return isset($this->configData['DB_HOSTNAME']) ? $this->configData['DB_HOSTNAME'] : 'localhost';
    }

    public function getDbUsername()
    {
        return isset($this->configData['DB_USERNAME']) ? $this->configData['DB_USERNAME'] : 'root';
    }

    public function getDbPassword()
    {
        return isset($this->configData['DB_PASSWORD']) ? $this->configData['DB_PASSWORD'] : '';
    }

    public function getDbDatabase()
    {
        return isset($this->configData['DB_DATABASE']) ? $this->configData['DB_DATABASE'] : '';
    }

    public function getDbPort()
    {
        return isset($this->configData['DB_PORT']) ? (int)$this->configData['DB_PORT'] : 3306;
    }

    public function getDbPrefix()
    {
        return isset($this->configData['DB_PREFIX']) ? $this->configData['DB_PREFIX'] : 'oc_';
    }

    public function getStoragePath()
    {
        if (isset($this->configData['DIR_STORAGE'])) {
            return rtrim($this->configData['DIR_STORAGE'], '/\\') . '/';
        }
        if (isset($this->configData['DIR_SYSTEM'])) {
            return rtrim($this->configData['DIR_SYSTEM'], '/\\') . '/storage/';
        }
        return $this->rootPath . '/system/storage/';
    }

    public function getLogPath()
    {
        if (isset($this->configData['DIR_LOGS'])) {
            return rtrim($this->configData['DIR_LOGS'], '/\\') . '/';
        }
        return $this->getStoragePath() . 'logs/';
    }

    public function getImagePath()
    {
        if (isset($this->configData['DIR_IMAGE'])) {
            return rtrim($this->configData['DIR_IMAGE'], '/\\') . '/';
        }
        return $this->rootPath . '/image/';
    }

    public function getAllConfig()
    {
        return $this->configData;
    }
}
