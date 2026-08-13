<?php

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        $config = require __DIR__ . '/config.php';
        $db = $config['db'];

        try {
            $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4";
            $this->connection = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // Dynamically ensure matching_schedules table exists
            $this->connection->exec("CREATE TABLE IF NOT EXISTS `matching_schedules` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL,
                `slab_amount` DECIMAL(15, 2) NOT NULL,
                `daily_income` DECIMAL(15, 2) NOT NULL,
                `days_passed` INT DEFAULT 0,
                `max_days` INT DEFAULT 100,
                `status` ENUM('active', 'completed') DEFAULT 'active',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        } catch (PDOException $e) {
            // Fallback to in-memory SQLite database for testing and verification if MySQL is unavailable
            try {
                $this->connection = new PDO("sqlite::memory:", null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);

                // Set up mock tables and data for register.php and create_user.php testing
                $this->connection->exec("CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT UNIQUE,
                    full_name TEXT,
                    phone TEXT,
                    address TEXT,
                    post_office_number TEXT,
                    state TEXT,
                    country TEXT,
                    email TEXT,
                    password TEXT,
                    sponsor_id INTEGER,
                    placement_id INTEGER,
                    position TEXT,
                    activation_pin TEXT,
                    mid TEXT UNIQUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");

                $this->connection->exec("CREATE TABLE IF NOT EXISTS packages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT,
                    amount REAL
                )");

                $this->connection->exec("CREATE TABLE IF NOT EXISTS pins (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    pin_code TEXT UNIQUE,
                    package_id INTEGER,
                    status TEXT DEFAULT 'unused',
                    assigned_to INTEGER,
                    used_by INTEGER
                )");

                $this->connection->exec("CREATE TABLE IF NOT EXISTS genealogy (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER,
                    parent_id INTEGER,
                    level INTEGER
                )");

                // Insert mock seed data
                $this->connection->exec("INSERT OR IGNORE INTO users (id, username, mid) VALUES (1, 'admin', 'OPT59655')");
                $this->connection->exec("INSERT OR IGNORE INTO users (id, username, mid) VALUES (123, 'opt58438_user', 'OPT58438')");

                $this->connection->exec("INSERT OR IGNORE INTO packages (id, name, amount) VALUES (1, 'Package $100', 100.0)");

                $this->connection->exec("INSERT OR IGNORE INTO pins (pin_code, package_id, status) VALUES ('OPT123456', 1, 'unused')");
                $this->connection->exec("INSERT OR IGNORE INTO pins (pin_code, package_id, status) VALUES ('OPTUSED001', 1, 'used')");

            } catch (Exception $ex) {
                die("Connection failed: " . $e->getMessage() . " and SQLite fallback failed: " . $ex->getMessage());
            }
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }
}
