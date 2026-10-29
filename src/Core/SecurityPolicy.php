<?php
/**
 * OpenCart Universal MCP Server
 * Compatible with PHP 5.4 - 8.4+
 *
 * @author    Anton Barinov <https://github.com/Anton-Barinov>
 * @license   MIT
 */

namespace OpenCartMcp\Core;

class SecurityPolicy
{
    /** @var bool */
    private $maskPii = true;

    /** @var bool */
    private $allowMutations = false;

    /** @var string|null */
    private $bearerToken = null;

    /** @var array */
    private $ipWhitelist = [];

    /**
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        if (isset($options['maskPii'])) {
            $this->maskPii = (bool)$options['maskPii'];
        }
        if (isset($options['allowMutations'])) {
            $this->allowMutations = (bool)$options['allowMutations'];
        }
        if (!empty($options['token'])) {
            $this->bearerToken = (string)$options['token'];
        }
        if (!empty($options['ipWhitelist']) && is_array($options['ipWhitelist'])) {
            $this->ipWhitelist = $options['ipWhitelist'];
        }
    }

    /**
     * Check if client IP is authorized
     *
     * @param string $ip
     * @return bool
     */
    public function isIpAllowed($ip)
    {
        if (empty($this->ipWhitelist)) {
            return true;
        }

        foreach ($this->ipWhitelist as $allowed) {
            if ($allowed === $ip || $allowed === '*' || $allowed === '127.0.0.1' && ($ip === '::1' || $ip === 'localhost')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate incoming Bearer token
     *
     * @param string $providedHeader
     * @return bool
     */
    public function validateToken($providedHeader)
    {
        if ($this->bearerToken === null || $this->bearerToken === '') {
            return true; // No token configured -> unrestricted local access
        }

        $token = '';
        if (preg_match('/Bearer\s+(.+)$/i', $providedHeader, $m)) {
            $token = trim($m[1]);
        } else {
            $token = trim($providedHeader);
        }

        return $token !== '' && hash_equals($this->bearerToken, $token);
    }

    /**
     * Are mutating operations (stock updates, price changes) permitted?
     *
     * @return bool
     */
    public function areMutationsAllowed()
    {
        return $this->allowMutations;
    }

    /**
     * Set mutation permission
     *
     * @param bool $allowed
     */
    public function setAllowMutations($allowed)
    {
        $this->allowMutations = (bool)$allowed;
    }

    /**
     * Sanitize and mask customer PII data (GDPR / 152-FZ compliance)
     *
     * @param array $data
     * @return array
     */
    public function sanitizeCustomerRecord(array $data)
    {
        // 1. Always strip authentication secrets
        unset($data['password'], $data['salt'], $data['token'], $data['code']);

        if (!$this->maskPii) {
            return $data;
        }

        // 2. Mask Personal Information
        if (isset($data['email'])) {
            $data['email'] = $this->maskEmail($data['email']);
        }
        if (isset($data['telephone'])) {
            $data['telephone'] = $this->maskPhone($data['telephone']);
        }
        if (isset($data['firstname'])) {
            $data['firstname'] = $this->maskName($data['firstname']);
        }
        if (isset($data['lastname'])) {
            $data['lastname'] = $this->maskName($data['lastname']);
        }
        if (isset($data['ip'])) {
            $data['ip'] = $this->maskIp($data['ip']);
        }

        return $data;
    }

    /**
     * Sanitize and mask order customer info
     *
     * @param array $order
     * @return array
     */
    public function sanitizeOrderRecord(array $order)
    {
        if (!$this->maskPii) {
            return $order;
        }

        if (isset($order['email'])) {
            $order['email'] = $this->maskEmail($order['email']);
        }
        if (isset($order['telephone'])) {
            $order['telephone'] = $this->maskPhone($order['telephone']);
        }
        if (isset($order['firstname'])) {
            $order['firstname'] = $this->maskName($order['firstname']);
        }
        if (isset($order['lastname'])) {
            $order['lastname'] = $this->maskName($order['lastname']);
        }
        if (isset($order['payment_firstname'])) {
            $order['payment_firstname'] = $this->maskName($order['payment_firstname']);
        }
        if (isset($order['payment_lastname'])) {
            $order['payment_lastname'] = $this->maskName($order['payment_lastname']);
        }
        if (isset($order['shipping_firstname'])) {
            $order['shipping_firstname'] = $this->maskName($order['shipping_firstname']);
        }
        if (isset($order['shipping_lastname'])) {
            $order['shipping_lastname'] = $this->maskName($order['shipping_lastname']);
        }
        if (isset($order['ip'])) {
            $order['ip'] = $this->maskIp($order['ip']);
        }

        return $order;
    }

    public function maskEmail($email)
    {
        if (!is_string($email) || strpos($email, '@') === false) {
            return '***';
        }

        list($user, $domain) = explode('@', $email, 2);
        $len = strlen($user);
        if ($len <= 2) {
            $maskedUser = substr($user, 0, 1) . '***';
        } else {
            $maskedUser = substr($user, 0, 2) . '***';
        }

        return $maskedUser . '@' . $domain;
    }

    public function maskPhone($phone)
    {
        if (!is_string($phone)) {
            return '***';
        }

        $digits = preg_replace('/[^\d]/', '', $phone);
        if (strlen($digits) < 6) {
            return '***';
        }

        // Keep first 2 and last 2 digits
        return substr($phone, 0, 4) . ' ***-**-' . substr($digits, -2);
    }

    public function maskName($name)
    {
        if (!is_string($name) || strlen($name) === 0) {
            return '***';
        }

        // Return first letter followed by asterisks (e.g. "Ivan" -> "I***")
        return mb_substr($name, 0, 1, 'UTF-8') . '***';
    }

    public function maskIp($ip)
    {
        if (!is_string($ip)) {
            return '***';
        }

        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            return $parts[0] . '.' . $parts[1] . '.*.*';
        }

        return substr($ip, 0, 6) . '***';
    }
}
