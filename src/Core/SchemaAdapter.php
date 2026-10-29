<?php
/**
 * OpenCart Universal MCP Server
 * Compatible with PHP 5.4 - 8.4+
 *
 * @author    Anton Barinov <https://github.com/Anton-Barinov>
 * @license   MIT
 */

namespace OpenCartMcp\Core;

class SchemaAdapter
{
    /** @var Database */
    private $db;

    /** @var string Major version ('2.x', '3.x', '4.x') */
    private $majorVersion = '3.x';

    /** @var int Default storefront language ID */
    private $defaultLanguageId = 1;

    /** @var string Default storefront currency */
    private $defaultCurrency = 'USD';

    /** @var array Settings cache */
    private $settingsCache = [];

    /** @var bool|null Cached check for ocStore main_category column */
    private $hasMainCategoryColumn = null;

    /**
     * @param Database $db
     * @param string $majorVersion
     */
    public function __construct(Database $db, $majorVersion = '3.x')
    {
        $this->db = $db;
        $this->majorVersion = $majorVersion;
        $this->detectLanguageAndCurrency();
    }

    /**
     * Auto-detect default language ID and currency
     */
    public function detectLanguageAndCurrency()
    {
        // 1. Load config_language from oc_setting
        $langCode = $this->getSetting('config', 'config_language');
        if (!$langCode) {
            $langCode = 'en-gb';
        }

        try {
            $row = $this->db->queryRow(
                "SELECT language_id FROM {prefix}language WHERE code = :code OR code LIKE :code_like LIMIT 1",
                ['code' => $langCode, 'code_like' => substr($langCode, 0, 2) . '%']
            );
            if ($row && isset($row['language_id'])) {
                $this->defaultLanguageId = (int)$row['language_id'];
            } else {
                // Fallback to lowest language_id
                $first = $this->db->queryRow("SELECT language_id FROM {prefix}language ORDER BY language_id ASC LIMIT 1");
                if ($first && isset($first['language_id'])) {
                    $this->defaultLanguageId = (int)$first['language_id'];
                }
            }
        } catch (\Exception $e) {
            $this->defaultLanguageId = 1;
        }

        // 2. Load default currency
        $curr = $this->getSetting('config', 'config_currency');
        $this->defaultCurrency = $curr ? $curr : 'USD';
    }

    /**
     * Get setting value by code and key
     *
     * @param string $code (e.g. 'config')
     * @param string $key  (e.g. 'config_name')
     * @param int $storeId
     * @return mixed
     */
    public function getSetting($code, $key, $storeId = 0)
    {
        $cacheKey = "{$code}.{$key}.{$storeId}";
        if (isset($this->settingsCache[$cacheKey])) {
            return $this->settingsCache[$cacheKey];
        }

        try {
            $row = $this->db->queryRow(
                "SELECT value, serialized FROM {prefix}setting WHERE code = :code AND `key` = :key AND store_id = :store_id LIMIT 1",
                ['code' => $code, 'key' => $key, 'store_id' => $storeId]
            );

            if (!$row) {
                // In OpenCart 2.0 / 2.1 store_id might be absent in some tables
                $row = $this->db->queryRow(
                    "SELECT value, serialized FROM {prefix}setting WHERE `key` = :key LIMIT 1",
                    ['key' => $key]
                );
            }

            if (!$row) {
                return null;
            }

            $val = $row['value'];
            $isSerialized = isset($row['serialized']) ? (bool)$row['serialized'] : false;

            if ($isSerialized) {
                // OpenCart 4 uses json, OpenCart 2/3 uses serialize
                if ($this->majorVersion === '4.x') {
                    $decoded = @json_decode($val, true);
                    $val = ($decoded !== null) ? $decoded : $val;
                } else {
                    $decoded = @unserialize($val);
                    $val = ($decoded !== false || $val === 'b:0;') ? $decoded : $val;
                }
            }

            $this->settingsCache[$cacheKey] = $val;
            return $val;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get SEO Keyword for query (e.g. product_id=42 or category_id=20)
     *
     * @param string $query Key-value query, e.g. 'product_id=42'
     * @param int|null $languageId
     * @return string
     */
    public function getSeoKeyword($query, $languageId = null)
    {
        if ($languageId === null) {
            $languageId = $this->defaultLanguageId;
        }

        try {
            if ($this->majorVersion === '4.x') {
                // OpenCart 4: table seo_url has `key` and `value`
                if (strpos($query, '=') !== false) {
                    list($k, $v) = explode('=', $query, 2);
                    $row = $this->db->queryRow(
                        "SELECT keyword FROM {prefix}seo_url WHERE `key` = :key AND `value` = :value AND (language_id = :lang OR language_id = 0) LIMIT 1",
                        ['key' => $k, 'value' => $v, 'lang' => $languageId]
                    );
                    return $row && isset($row['keyword']) ? $row['keyword'] : '';
                }
            } elseif ($this->majorVersion === '3.x') {
                // OpenCart 3: table seo_url has `query` and `keyword`
                $row = $this->db->queryRow(
                    "SELECT keyword FROM {prefix}seo_url WHERE `query` = :query AND (language_id = :lang OR language_id = 0) LIMIT 1",
                    ['query' => $query, 'lang' => $languageId]
                );
                return $row && isset($row['keyword']) ? $row['keyword'] : '';
            } else {
                // OpenCart 2.x: table url_alias has `query` and `keyword`
                $row = $this->db->queryRow(
                    "SELECT keyword FROM {prefix}url_alias WHERE `query` = :query LIMIT 1",
                    ['query' => $query]
                );
                return $row && isset($row['keyword']) ? $row['keyword'] : '';
            }
        } catch (\Exception $e) {
            return '';
        }

        return '';
    }

    /**
     * Get order status name by order_status_id
     *
     * @param int $orderStatusId
     * @param int|null $languageId
     * @return string
     */
    public function getOrderStatusName($orderStatusId, $languageId = null)
    {
        if ($languageId === null) {
            $languageId = $this->defaultLanguageId;
        }

        try {
            $row = $this->db->queryRow(
                "SELECT name FROM {prefix}order_status WHERE order_status_id = :id AND language_id = :lang LIMIT 1",
                ['id' => (int)$orderStatusId, 'lang' => $languageId]
            );
            return $row && isset($row['name']) ? $row['name'] : 'Unknown (' . $orderStatusId . ')';
        } catch (\Exception $e) {
            return 'Unknown (' . $orderStatusId . ')';
        }
    }

    /**
     * Get all active order statuses mapping [id => name]
     *
     * @param int|null $languageId
     * @return array
     */
    public function getOrderStatuses($languageId = null)
    {
        if ($languageId === null) {
            $languageId = $this->defaultLanguageId;
        }

        try {
            $rows = $this->db->query(
                "SELECT order_status_id, name FROM {prefix}order_status WHERE language_id = :lang ORDER BY name ASC",
                ['lang' => $languageId]
            );
            $statuses = [];
            foreach ($rows as $r) {
                $statuses[(int)$r['order_status_id']] = $r['name'];
            }
            return $statuses;
        } catch (\Exception $e) {
            return [];
        }
    }

    public function getDefaultLanguageId()
    {
        return $this->defaultLanguageId;
    }

    public function getDefaultCurrency()
    {
        return $this->defaultCurrency;
    }

    public function getMajorVersion()
    {
        return $this->majorVersion;
    }

    /**
     * Check if ocStore main_category column exists in product_to_category
     *
     * @return bool
     */
    public function hasMainCategoryColumn()
    {
        if ($this->hasMainCategoryColumn !== null) {
            return $this->hasMainCategoryColumn;
        }
        try {
            // Universal ANSI SQL probe (works across MySQL, MariaDB, SQLite, PostgreSQL)
            $this->db->query("SELECT main_category FROM {prefix}product_to_category LIMIT 0");
            $this->hasMainCategoryColumn = true;
        } catch (\Exception $e) {
            $this->hasMainCategoryColumn = false;
        }
        return $this->hasMainCategoryColumn;
    }

    /**
     * Get primary / main category ID for a product (supports ocStore main_category)
     *
     * @param int $productId
     * @return int
     */
    public function getMainCategoryId($productId)
    {
        $productId = (int)$productId;
        try {
            if ($this->hasMainCategoryColumn()) {
                $row = $this->db->queryRow(
                    "SELECT category_id FROM {prefix}product_to_category WHERE product_id = :id AND main_category = 1 LIMIT 1",
                    ['id' => $productId]
                );
                if ($row && isset($row['category_id'])) {
                    return (int)$row['category_id'];
                }
            }
            // Standard OpenCart fallback: first category
            $first = $this->db->queryRow(
                "SELECT category_id FROM {prefix}product_to_category WHERE product_id = :id ORDER BY category_id ASC LIMIT 1",
                ['id' => $productId]
            );
            return ($first && isset($first['category_id'])) ? (int)$first['category_id'] : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Resolve store base URL (supports multi-store setups)
     *
     * @param int $storeId
     * @return string
     */
    public function getStoreUrl($storeId = 0)
    {
        $url = $this->getSetting('config', 'config_url', $storeId);
        if (!$url) {
            $url = $this->getSetting('config', 'config_ssl', $storeId);
        }
        if (!$url) {
            if (defined('HTTP_CATALOG')) {
                $url = HTTP_CATALOG;
            } elseif (defined('HTTP_SERVER')) {
                $url = HTTP_SERVER;
            } else {
                $url = '/';
            }
        }
        return rtrim($url, '/') . '/';
    }

    /**
     * Format currency amount according to store currency definitions
     *
     * @param float|int|string $amount
     * @param string|null $currencyCode
     * @return string
     */
    public function formatCurrency($amount, $currencyCode = null)
    {
        if ($currencyCode === null) {
            $currencyCode = $this->defaultCurrency;
        }
        try {
            $curr = $this->db->queryRow(
                "SELECT symbol_left, symbol_right, decimal_place FROM {prefix}currency WHERE code = :code LIMIT 1",
                ['code' => $currencyCode]
            );
            if ($curr) {
                $symbolLeft = isset($curr['symbol_left']) ? $curr['symbol_left'] : '';
                $symbolRight = isset($curr['symbol_right']) ? $curr['symbol_right'] : '';
                $decimals = isset($curr['decimal_place']) ? (int)$curr['decimal_place'] : 2;
                $formatted = number_format((float)$amount, $decimals, '.', ' ');
                return trim($symbolLeft . $formatted . $symbolRight);
            }
        } catch (\Exception $e) {
            // Fallback
        }
        return number_format((float)$amount, 2, '.', ' ') . ' ' . $currencyCode;
    }
}
