<?php
/**
 * OpenCart Universal MCP Server
 * Test Fixture: Seed schema for OpenCart 2.x and 3.x/4.x test suite
 */

namespace OpenCartMcp\Tests\Fixtures;

use OpenCartMcp\Core\Database;

class SchemaFixture
{
    public static function setUp(Database $db)
    {
        // 1. Languages
        $db->execute("DROP TABLE IF EXISTS {prefix}language");
        $db->execute("CREATE TABLE {prefix}language (
            language_id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(32) NOT NULL,
            code VARCHAR(5) NOT NULL,
            locale VARCHAR(255) NOT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->execute("INSERT INTO {prefix}language (language_id, name, code, locale, status) VALUES 
            (1, 'English', 'en-gb', 'en_US.UTF-8', 1),
            (2, 'Russian', 'ru-ru', 'ru_RU.UTF-8', 1)");

        // 2. Settings
        $db->execute("DROP TABLE IF EXISTS {prefix}setting");
        $db->execute("CREATE TABLE {prefix}setting (
            setting_id INT AUTO_INCREMENT PRIMARY KEY,
            store_id INT NOT NULL DEFAULT 0,
            code VARCHAR(128) NOT NULL,
            `key` VARCHAR(128) NOT NULL,
            value TEXT NOT NULL,
            serialized TINYINT(1) NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->execute("INSERT INTO {prefix}setting (store_id, code, `key`, value, serialized) VALUES 
            (0, 'config', 'config_name', 'Demo OpenCart Store', 0),
            (0, 'config', 'config_language', 'en-gb', 0),
            (0, 'config', 'config_currency', 'USD', 0)");

        // 3. Manufacturers
        $db->execute("DROP TABLE IF EXISTS {prefix}manufacturer");
        $db->execute("CREATE TABLE {prefix}manufacturer (
            manufacturer_id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(64) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->execute("INSERT INTO {prefix}manufacturer (manufacturer_id, name) VALUES (1, 'Apple'), (2, 'Samsung')");

        // 4. Products & Descriptions
        $db->execute("DROP TABLE IF EXISTS {prefix}product");
        $db->execute("CREATE TABLE {prefix}product (
            product_id INT AUTO_INCREMENT PRIMARY KEY,
            model VARCHAR(64) NOT NULL,
            sku VARCHAR(64) NOT NULL,
            quantity INT NOT NULL DEFAULT 0,
            price DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
            status TINYINT(1) NOT NULL DEFAULT 1,
            image VARCHAR(255) DEFAULT NULL,
            manufacturer_id INT NOT NULL DEFAULT 0,
            date_added DATETIME NOT NULL,
            date_modified DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->execute("DROP TABLE IF EXISTS {prefix}product_description");
        $db->execute("CREATE TABLE {prefix}product_description (
            product_id INT NOT NULL,
            language_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            tag TEXT,
            meta_title VARCHAR(255),
            meta_description TEXT,
            meta_keyword TEXT,
            PRIMARY KEY (product_id, language_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->execute("INSERT INTO {prefix}product (product_id, model, sku, quantity, price, status, image, manufacturer_id, date_added, date_modified) VALUES
            (101, 'iPhone 15 Pro', 'APL-IPH15P', 15, 999.0000, 1, 'catalog/iphone.jpg', 1, NOW(), NOW()),
            (102, 'Samsung Galaxy S24', 'SAM-GS24', 2, 799.0000, 1, 'catalog/s24.jpg', 2, NOW(), NOW()),
            (103, 'MacBook Air M3', 'APL-MBA-M3', 0, 1199.0000, 1, 'catalog/macbook.jpg', 1, NOW(), NOW())");

        $db->execute("INSERT INTO {prefix}product_description (product_id, language_id, name, description, tag, meta_title, meta_description, meta_keyword) VALUES
            (101, 1, 'Apple iPhone 15 Pro Titanium', 'Flagship Apple smartphone with A17 Pro chip', 'apple, smartphone, iphone', 'Buy Apple iPhone 15 Pro', 'Best price for iPhone 15 Pro', 'iphone, apple'),
            (102, 1, 'Samsung Galaxy S24 AI Phone', 'Flagship Samsung phone with Galaxy AI', 'samsung, galaxy, s24', 'Buy Samsung S24', 'Samsung S24 flagship phone', 'samsung, s24'),
            (103, 1, 'Apple MacBook Air 13 M3', 'Ultra-light laptop with M3 chip', 'apple, macbook, laptop', 'Buy MacBook Air M3', 'MacBook Air M3 specs', 'macbook, apple')");

        // 5. Product to Category
        $db->execute("DROP TABLE IF EXISTS {prefix}product_to_category");
        $db->execute("CREATE TABLE {prefix}product_to_category (
            product_id INT NOT NULL,
            category_id INT NOT NULL,
            PRIMARY KEY (product_id, category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->execute("INSERT INTO {prefix}product_to_category (product_id, category_id) VALUES (101, 20), (102, 20), (103, 25)");

        // 5b. Specials & Images
        $db->execute("DROP TABLE IF EXISTS {prefix}product_special");
        $db->execute("CREATE TABLE {prefix}product_special (
            product_special_id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            customer_group_id INT NOT NULL DEFAULT 1,
            priority INT NOT NULL DEFAULT 1,
            price DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
            date_start DATE NULL DEFAULT NULL,
            date_end DATE NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->execute("DROP TABLE IF EXISTS {prefix}product_image");
        $db->execute("CREATE TABLE {prefix}product_image (
            product_image_id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            image VARCHAR(255) DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->execute("DROP TABLE IF EXISTS {prefix}product_attribute");
        $db->execute("CREATE TABLE {prefix}product_attribute (
            product_id INT NOT NULL,
            attribute_id INT NOT NULL,
            language_id INT NOT NULL,
            text TEXT NOT NULL,
            PRIMARY KEY (product_id, attribute_id, language_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->execute("DROP TABLE IF EXISTS {prefix}attribute_description");
        $db->execute("CREATE TABLE {prefix}attribute_description (
            attribute_id INT NOT NULL,
            language_id INT NOT NULL,
            name VARCHAR(64) NOT NULL,
            PRIMARY KEY (attribute_id, language_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // 6. Categories & Descriptions
        $db->execute("DROP TABLE IF EXISTS {prefix}category");
        $db->execute("CREATE TABLE {prefix}category (
            category_id INT AUTO_INCREMENT PRIMARY KEY,
            parent_id INT NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            status TINYINT(1) NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->execute("DROP TABLE IF EXISTS {prefix}category_description");
        $db->execute("CREATE TABLE {prefix}category_description (
            category_id INT NOT NULL,
            language_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            PRIMARY KEY (category_id, language_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->execute("INSERT INTO {prefix}category (category_id, parent_id, sort_order, status) VALUES
            (10, 0, 1, 1),
            (20, 10, 1, 1),
            (25, 10, 2, 1)");

        $db->execute("INSERT INTO {prefix}category_description (category_id, language_id, name) VALUES
            (10, 1, 'Electronics'),
            (20, 1, 'Smartphones'),
            (25, 1, 'Laptops')");

        // 7. Order Statuses
        $db->execute("DROP TABLE IF EXISTS {prefix}order_status");
        $db->execute("CREATE TABLE {prefix}order_status (
            order_status_id INT NOT NULL,
            language_id INT NOT NULL,
            name VARCHAR(32) NOT NULL,
            PRIMARY KEY (order_status_id, language_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->execute("INSERT INTO {prefix}order_status (order_status_id, language_id, name) VALUES
            (1, 1, 'Pending'),
            (2, 1, 'Processing'),
            (3, 1, 'Shipped'),
            (5, 1, 'Complete'),
            (7, 1, 'Canceled')");

        // 8. Orders
        $db->execute("DROP TABLE IF EXISTS {prefix}order");
        $db->execute("CREATE TABLE {prefix}order (
            order_id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL DEFAULT 0,
            firstname VARCHAR(32) NOT NULL,
            lastname VARCHAR(32) NOT NULL,
            email VARCHAR(96) NOT NULL,
            telephone VARCHAR(32) NOT NULL,
            total DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
            order_status_id INT NOT NULL DEFAULT 0,
            currency_code VARCHAR(3) NOT NULL,
            payment_method VARCHAR(128) NOT NULL,
            shipping_method VARCHAR(128) NOT NULL,
            shipping_city VARCHAR(128) NOT NULL,
            shipping_country VARCHAR(128) NOT NULL,
            ip VARCHAR(40) NOT NULL,
            date_added DATETIME NOT NULL,
            date_modified DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->execute("INSERT INTO {prefix}order (order_id, customer_id, firstname, lastname, email, telephone, total, order_status_id, currency_code, payment_method, shipping_method, shipping_city, shipping_country, ip, date_added, date_modified) VALUES
            (501, 1, 'Alex', 'Smith', 'alex.smith@example.com', '+1 555-123-4567', 999.0000, 5, 'USD', 'Stripe Credit Card', 'Express Shipping', 'New York', 'United States', '192.168.1.10', NOW(), NOW()),
            (502, 2, 'Elena', 'Petrova', 'elena.p@testmail.org', '+7 (999) 765-43-21', 799.0000, 2, 'USD', 'PayPal Express', 'Standard Courier', 'Moscow', 'Russia', '10.0.0.5', NOW(), NOW())");

        // 9. Order Products
        $db->execute("DROP TABLE IF EXISTS {prefix}order_product");
        $db->execute("CREATE TABLE {prefix}order_product (
            order_product_id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            model VARCHAR(64) NOT NULL,
            quantity INT NOT NULL,
            price DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
            total DECIMAL(15,4) NOT NULL DEFAULT 0.0000
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->execute("INSERT INTO {prefix}order_product (order_product_id, order_id, product_id, name, model, quantity, price, total) VALUES
            (1, 501, 101, 'Apple iPhone 15 Pro Titanium', 'APL-IPH15P', 1, 999.0000, 999.0000),
            (2, 502, 102, 'Samsung Galaxy S24 AI Phone', 'SAM-GS24', 1, 799.0000, 799.0000)");

        // 10. Order Option & Total & History
        $db->execute("DROP TABLE IF EXISTS {prefix}order_option");
        $db->execute("CREATE TABLE {prefix}order_option (
            order_option_id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            order_product_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            value TEXT NOT NULL,
            type VARCHAR(32) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->execute("DROP TABLE IF EXISTS {prefix}order_total");
        $db->execute("CREATE TABLE {prefix}order_total (
            order_total_id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            code VARCHAR(32) NOT NULL,
            title VARCHAR(255) NOT NULL,
            value DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
            sort_order INT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->execute("INSERT INTO {prefix}order_total (order_id, code, title, value, sort_order) VALUES
            (501, 'sub_total', 'Sub-Total', 999.0000, 1),
            (501, 'total', 'Total', 999.0000, 9)");

        $db->execute("DROP TABLE IF EXISTS {prefix}order_history");
        $db->execute("CREATE TABLE {prefix}order_history (
            order_history_id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            order_status_id INT NOT NULL,
            notify TINYINT(1) NOT NULL DEFAULT 0,
            comment TEXT NOT NULL,
            date_added DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->execute("INSERT INTO {prefix}order_history (order_id, order_status_id, notify, comment, date_added) VALUES
            (501, 1, 0, 'Order created', NOW()),
            (501, 5, 1, 'Payment completed via Stripe', NOW())");

        // 11. Customers & Addresses
        $db->execute("DROP TABLE IF EXISTS {prefix}customer");
        $db->execute("CREATE TABLE {prefix}customer (
            customer_id INT AUTO_INCREMENT PRIMARY KEY,
            customer_group_id INT NOT NULL DEFAULT 1,
            firstname VARCHAR(32) NOT NULL,
            lastname VARCHAR(32) NOT NULL,
            email VARCHAR(96) NOT NULL,
            telephone VARCHAR(32) NOT NULL,
            password VARCHAR(255) NOT NULL,
            salt VARCHAR(9) NOT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            date_added DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->execute("INSERT INTO {prefix}customer (customer_id, customer_group_id, firstname, lastname, email, telephone, password, salt, status, date_added) VALUES
            (1, 1, 'Alex', 'Smith', 'alex.smith@example.com', '+1 555-123-4567', 'secret_hash', 'salt1', 1, NOW()),
            (2, 1, 'Elena', 'Petrova', 'elena.p@testmail.org', '+7 (999) 765-43-21', 'secret_hash_2', 'salt2', 1, NOW())");

        $db->execute("DROP TABLE IF EXISTS {prefix}address");
        $db->execute("CREATE TABLE {prefix}address (
            address_id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NOT NULL,
            address_1 VARCHAR(128) NOT NULL,
            city VARCHAR(128) NOT NULL,
            postcode VARCHAR(10) NOT NULL,
            country_id INT NOT NULL DEFAULT 0,
            zone_id INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->execute("INSERT INTO {prefix}address (address_id, customer_id, address_1, city, postcode) VALUES
            (1, 1, '123 5th Ave', 'New York', '10001'),
            (2, 2, 'Tverskaya 1', 'Moscow', '101000')");

        // 12. SEO URL (OpenCart 3.x/4.x compatible)
        $db->execute("DROP TABLE IF EXISTS {prefix}seo_url");
        $db->execute("CREATE TABLE {prefix}seo_url (
            seo_url_id INT AUTO_INCREMENT PRIMARY KEY,
            store_id INT NOT NULL DEFAULT 0,
            language_id INT NOT NULL DEFAULT 1,
            query VARCHAR(255) NOT NULL,
            keyword VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->execute("INSERT INTO {prefix}seo_url (store_id, language_id, query, keyword) VALUES
            (0, 1, 'product_id=101', 'apple-iphone-15-pro'),
            (0, 1, 'product_id=102', 'samsung-galaxy-s24'),
            (0, 1, 'product_id=103', 'apple-macbook-air-m3'),
            (0, 1, 'category_id=20', 'smartphones')");

        // 13. Modifications (OCMOD)
        $db->execute("DROP TABLE IF EXISTS {prefix}modification");
        $db->execute("CREATE TABLE {prefix}modification (
            modification_id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(64) NOT NULL,
            code VARCHAR(64) NOT NULL,
            author VARCHAR(64) NOT NULL,
            version VARCHAR(32) NOT NULL,
            status TINYINT(1) NOT NULL DEFAULT 1,
            date_added DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->execute("INSERT INTO {prefix}modification (name, code, author, version, status, date_added) VALUES
            ('Fast Checkout Fix', 'fast_checkout', 'Anton Barinov', '1.0.0', 1, NOW())");
    }
}
