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

            // Dynamically ensure pin_code column exists in users table
            try {
                $this->connection->exec("ALTER TABLE `users` ADD COLUMN `pin_code` VARCHAR(20) DEFAULT NULL AFTER `rank_id`");
            } catch (PDOException $e) {
                // Column probably already exists, which is fine
            }

        } catch (PDOException $e) {
            die("Connection failed: " . $e->getMessage());
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
