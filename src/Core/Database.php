<?php
/**
 * OpenCart Universal MCP Server
 * Compatible with PHP 5.4 - 8.4+
 *
 * @author    Anton Barinov <https://github.com/Anton-Barinov>
 * @license   MIT
 */

namespace OpenCartMcp\Core;

class Database
{
    /** @var \PDO|\mysqli|null */
    private $connection;

    /** @var string */
    private $driverType = 'pdo'; // 'pdo' or 'mysqli'

    /** @var string */
    private $prefix = 'oc_';

    /** @var bool */
    private $readOnly = true;

    /** @var array */
    private $config = [];

    /**
     * @param array $config Connection options
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'host'     => 'localhost',
            'user'     => 'root',
            'password' => '',
            'database' => '',
            'port'     => 3306,
            'prefix'   => 'oc_',
            'charset'  => 'utf8mb4',
            'readOnly' => true,
        ], $config);

        if (isset($config['db_prefix']) && !isset($config['prefix'])) {
            $this->config['prefix'] = $config['db_prefix'];
        }

        $this->prefix = $this->config['prefix'];
        $this->readOnly = (bool)$this->config['readOnly'];
        $this->connect();
    }

    /**
     * Establish database connection (PDO preferred, mysqli fallback)
     *
     * @throws \RuntimeException
     */
    public function connect()
    {
        if (isset($this->config['pdo']) && $this->config['pdo'] instanceof \PDO) {
            $this->connection = $this->config['pdo'];
            $this->driverType = 'pdo';
            return;
        }

        $host = $this->config['host'];
        $port = (int)$this->config['port'];
        $user = $this->config['user'];
        $pass = $this->config['password'];
        $dbName = $this->config['database'];
        $charset = $this->config['charset'];

        // Normalize localhost socket handling
        if ($host === 'localhost' && !empty($this->config['socket'])) {
            $host = $this->config['socket'];
        }

        // 1. Try PDO if available
        if (extension_loaded('pdo') && extension_loaded('pdo_mysql')) {
            try {
                $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset={$charset}";
                $options = [
                    \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                    \PDO::ATTR_EMULATE_PREPARES   => false,
                ];

                $this->connection = new \PDO($dsn, $user, $pass, $options);
                $this->driverType = 'pdo';
                return;
            } catch (\Exception $e) {
                // If utf8mb4 failed on legacy MySQL, fallback to utf8
                if (strpos($e->getMessage(), 'Unknown character set') !== false) {
                    $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8";
                    $this->connection = new \PDO($dsn, $user, $pass, $options);
                    $this->driverType = 'pdo';
                    return;
                }
                // Fallback to mysqli if PDO failed
            }
        }

        // 2. Fallback to mysqli
        if (extension_loaded('mysqli')) {
            $mysqli = mysqli_init();
            if (!$mysqli) {
                throw new \RuntimeException("Failed to initialize mysqli");
            }

            $mysqli->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
            $connected = @$mysqli->real_connect($host, $user, $pass, $dbName, $port);

            if (!$connected) {
                throw new \RuntimeException("MySQL connection failed: " . mysqli_connect_error());
            }

            @$mysqli->set_charset($charset);
            $this->connection = $mysqli;
            $this->driverType = 'mysqli';
            return;
        }

        throw new \RuntimeException("Neither PDO MySQL nor mysqli extension is available in PHP.");
    }

    /**
     * Set read-only operational mode
     *
     * @param bool $readOnly
     */
    public function setReadOnly($readOnly)
    {
        $this->readOnly = (bool)$readOnly;
    }

    /**
     * Is the database connection currently in read-only mode?
     *
     * @return bool
     */
    public function isReadOnly()
    {
        return $this->readOnly;
    }

    /**
     * Execute parameterized query and return all matching rows
     *
     * @param string $sql
     * @param array $params
     * @return array
     * @throws \RuntimeException
     */
    public function query($sql, array $params = [])
    {
        $sql = $this->replacePrefix($sql);
        $this->assertQueryAllowed($sql);

        if ($this->driverType === 'pdo') {
            /** @var \PDO $pdo */
            $pdo = $this->connection;
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Exception $e) {
                throw new \RuntimeException("Database Query Error: " . $e->getMessage() . " [SQL: {$sql}]");
            }
        } else {
            /** @var \mysqli $mysqli */
            $mysqli = $this->connection;
            // For mysqli without PDO, interpolate params safely
            $safeSql = $this->interpolateParams($sql, $params);
            $result = @$mysqli->query($safeSql);
            if ($result === false) {
                throw new \RuntimeException("Database Query Error: " . $mysqli->error . " [SQL: {$safeSql}]");
            }

            if ($result === true) {
                return [];
            }

            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
            return $rows;
        }
    }

    /**
     * Execute parameterized query and return single row
     *
     * @param string $sql
     * @param array $params
     * @return array|null
     */
    public function queryRow($sql, array $params = [])
    {
        $rows = $this->query($sql, $params);
        return !empty($rows) ? $rows[0] : null;
    }

    /**
     * Execute parameterized query and return scalar value
     *
     * @param string $sql
     * @param array $params
     * @return mixed
     */
    public function queryScalar($sql, array $params = [])
    {
        $row = $this->queryRow($sql, $params);
        if ($row) {
            $vals = array_values($row);
            return isset($vals[0]) ? $vals[0] : null;
        }
        return null;
    }

    /**
     * Execute mutating query (INSERT / UPDATE / DELETE)
     *
     * @param string $sql
     * @param array $params
     * @return int Number of affected rows
     * @throws \RuntimeException
     */
    public function execute($sql, array $params = [])
    {
        $sql = $this->replacePrefix($sql);

        if ($this->readOnly) {
            throw new \RuntimeException("Write operation blocked: Database connection is in strict READ-ONLY mode. Pass --allow-mutations or enable write permissions to modify data.");
        }

        if ($this->driverType === 'pdo') {
            /** @var \PDO $pdo */
            $pdo = $this->connection;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } else {
            /** @var \mysqli $mysqli */
            $mysqli = $this->connection;
            $safeSql = $this->interpolateParams($sql, $params);
            $res = $mysqli->query($safeSql);
            if ($res === false) {
                throw new \RuntimeException("Database Execution Error: " . $mysqli->error);
            }
            return $mysqli->affected_rows;
        }
    }

    /**
     * Get last inserted ID
     *
     * @return int|string
     */
    public function getLastId()
    {
        if ($this->driverType === 'pdo') {
            return $this->connection->lastInsertId();
        } else {
            return $this->connection->insert_id;
        }
    }

    /**
     * Safely escape a string literal
     *
     * @param string $value
     * @return string
     */
    public function escape($value)
    {
        if ($this->driverType === 'pdo') {
            $quoted = $this->connection->quote((string)$value);
            // quote() surrounds with quotes, strip them for compatibility with OpenCart escape()
            if (strlen($quoted) >= 2 && $quoted[0] === "'" && substr($quoted, -1) === "'") {
                return substr($quoted, 1, -1);
            }
            return $quoted;
        } else {
            return $this->connection->real_escape_string((string)$value);
        }
    }

    /**
     * Get table prefix
     *
     * @return string
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

    /**
     * Replace {DB_PREFIX} or standard 'oc_' in SQL query with actual configured prefix
     *
     * @param string $sql
     * @return string
     */
    public function replacePrefix($sql)
    {
        // Replace {prefix} or {DB_PREFIX}
        $sql = str_replace(['{DB_PREFIX}', '{prefix}'], $this->prefix, $sql);

        // Also replace oc_ if actual prefix is different
        if ($this->prefix !== 'oc_') {
            $sql = preg_replace('/(\s|`|,|\()oc_([a-zA-Z0-9_]+)/', '$1' . $this->prefix . '$2', $sql);
        }

        return $sql;
    }

    /**
     * Verify whether query is allowed in read-only mode
     *
     * @param string $sql
     * @throws \RuntimeException
     */
    private function assertQueryAllowed($sql)
    {
        if (!$this->readOnly) {
            return;
        }

        // Strip SQL comments to prevent comment-hidden injections
        $clean = preg_replace('@/\*.*?\*/@s', ' ', $sql);
        $clean = preg_replace('/--[^\r\n]*/', ' ', $clean);
        $clean = preg_replace('/#[^\r\n]*/', ' ', $clean);
        $clean = trim($clean);

        // Block stacked queries
        if (preg_match('/;\s*\S/', $clean)) {
            throw new \RuntimeException("Security Violation: Stacked queries (multiple statements) are strictly forbidden in read-only mode.");
        }

        // Block forbidden mutating statements
        $mutationPattern = '/\b(INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|TRUNCATE|RENAME|CREATE|GRANT|REVOKE|FLUSH)\b/i';
        if (preg_match($mutationPattern, $clean)) {
            throw new \RuntimeException("Write operation blocked: Database connection is in strict READ-ONLY mode.");
        }

        // Block Common Table Expression (CTE) mutations
        if (preg_match('/\bWITH\b.*?\b(INSERT|UPDATE|DELETE)\b/is', $clean)) {
            throw new \RuntimeException("Security Violation: CTE mutations are forbidden in read-only mode.");
        }
    }

    /**
     * Interpolate parameters into SQL query safely (for mysqli fallback)
     *
     * @param string $sql
     * @param array $params
     * @return string
     */
    private function interpolateParams($sql, array $params)
    {
        if (empty($params)) {
            return $sql;
        }

        // Positional parameters (?)
        if (isset($params[0])) {
            $idx = 0;
            return preg_replace_callback('/\?/', function () use (&$idx, $params) {
                if (!isset($params[$idx])) {
                    return 'NULL';
                }
                $val = $params[$idx++];
                return $this->formatParam($val);
            }, $sql);
        }

        // Named parameters (:name)
        foreach ($params as $k => $v) {
            $needle = strpos($k, ':') === 0 ? $k : ':' . $k;
            $sql = str_replace($needle, $this->formatParam($v), $sql);
        }

        return $sql;
    }

    private function formatParam($val)
    {
        if (is_null($val)) {
            return 'NULL';
        }
        if (is_int($val) || is_float($val)) {
            return (string)$val;
        }
        if (is_bool($val)) {
            return $val ? '1' : '0';
        }
        return "'" . $this->escape($val) . "'";
    }
}
