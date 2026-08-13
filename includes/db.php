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
            // Fallback to SQLite
            $sqlitePath = dirname(__DIR__) . '/database.sqlite';
            $isNew = !file_exists($sqlitePath);
            try {
                $this->connection = new PDO("sqlite:" . $sqlitePath);
                $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                if ($isNew) {
                    $this->initializeSQLite();
                }
            } catch (PDOException $se) {
                die("Connection failed (MySQL): " . $e->getMessage() . " and SQLite fallback failed: " . $se->getMessage());
            }
        }
    }

    private function initializeSQLite() {
        $queries = [
            "CREATE TABLE IF NOT EXISTS `users` (
              `id` INTEGER PRIMARY KEY AUTOINCREMENT,
              `mid` VARCHAR(50) UNIQUE DEFAULT NULL,
              `username` VARCHAR(50) UNIQUE NOT NULL,
              `full_name` VARCHAR(100) DEFAULT NULL,
              `phone` VARCHAR(20) DEFAULT NULL,
              `address` TEXT DEFAULT NULL,
              `post_office_number` VARCHAR(20) DEFAULT NULL,
              `state` VARCHAR(100) DEFAULT NULL,
              `country` VARCHAR(100) DEFAULT NULL,
              `email` VARCHAR(100) NOT NULL,
              `password` VARCHAR(255) NOT NULL,
              `sponsor_id` INT DEFAULT NULL,
              `placement_id` INT DEFAULT NULL,
              `position` VARCHAR(20) DEFAULT NULL,
              `rank_id` INT DEFAULT 0,
              `total_investment` DECIMAL(15, 2) DEFAULT 0.00,
              `left_leg_business` DECIMAL(15, 2) DEFAULT 0.00,
              `right_leg_business` DECIMAL(15, 2) DEFAULT 0.00,
              `rank_income_days` INT DEFAULT 0,
              `status` VARCHAR(20) DEFAULT 'active',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (`sponsor_id`) REFERENCES `users`(`id`),
              FOREIGN KEY (`placement_id`) REFERENCES `users`(`id`)
            )",

            "CREATE TABLE IF NOT EXISTS `packages` (
              `id` INTEGER PRIMARY KEY AUTOINCREMENT,
              `name` VARCHAR(50) NOT NULL,
              `amount` DECIMAL(15, 2) NOT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",

            "CREATE TABLE IF NOT EXISTS `ranks` (
              `id` INTEGER PRIMARY KEY AUTOINCREMENT,
              `name` VARCHAR(50) NOT NULL,
              `matching_business` DECIMAL(15, 2) NOT NULL,
              `daily_income` DECIMAL(15, 2) NOT NULL,
              `duration_days` INT NOT NULL,
              `total_cap_multiplier` DECIMAL(5, 2) NOT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",

            "CREATE TABLE IF NOT EXISTS `investments` (
              `id` INTEGER PRIMARY KEY AUTOINCREMENT,
              `user_id` INT NOT NULL,
              `package_id` INT NOT NULL,
              `amount` DECIMAL(15, 2) NOT NULL,
              `roi_earned` DECIMAL(15, 2) DEFAULT 0.00,
              `total_earned` DECIMAL(15, 2) DEFAULT 0.00,
              `days_passed` INT DEFAULT 0,
              `last_roi_at` DATE DEFAULT NULL,
              `status` VARCHAR(20) DEFAULT 'active',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
              FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`)
            )",

            "CREATE TABLE IF NOT EXISTS `transactions` (
              `id` INTEGER PRIMARY KEY AUTOINCREMENT,
              `user_id` INT NOT NULL,
              `related_user_id` INT DEFAULT NULL,
              `investment_id` INT DEFAULT NULL,
              `level` INT DEFAULT NULL,
              `type` VARCHAR(50) NOT NULL,
              `amount` DECIMAL(15, 2) NOT NULL,
              `fee` DECIMAL(15, 2) DEFAULT 0.00,
              `net_amount` DECIMAL(15, 2) NOT NULL,
              `description` TEXT,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
              FOREIGN KEY (`related_user_id`) REFERENCES `users`(`id`)
            )",

            "CREATE TABLE IF NOT EXISTS `genealogy` (
              `id` INTEGER PRIMARY KEY AUTOINCREMENT,
              `user_id` INT NOT NULL,
              `parent_id` INT NOT NULL,
              `level` INT NOT NULL,
              FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
              FOREIGN KEY (`parent_id`) REFERENCES `users`(`id`)
            )",

            "CREATE TABLE IF NOT EXISTS `user_wallets` (
              `id` INTEGER PRIMARY KEY AUTOINCREMENT,
              `user_id` INT NOT NULL,
              `network` VARCHAR(50) DEFAULT 'TRC20',
              `address` VARCHAR(255) NOT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
            )",

            "CREATE TABLE IF NOT EXISTS `admins` (
              `id` INTEGER PRIMARY KEY AUTOINCREMENT,
              `username` VARCHAR(50) UNIQUE NOT NULL,
              `password` VARCHAR(255) NOT NULL,
              `email` VARCHAR(100) UNIQUE NOT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )",

            "CREATE TABLE IF NOT EXISTS `pins` (
              `id` INTEGER PRIMARY KEY AUTOINCREMENT,
              `pin_code` VARCHAR(20) UNIQUE NOT NULL,
              `package_id` INT NOT NULL,
              `status` VARCHAR(20) DEFAULT 'unused',
              `assigned_to` INT DEFAULT NULL,
              `used_by` INT DEFAULT NULL,
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`),
              FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`),
              FOREIGN KEY (`used_by`) REFERENCES `users`(`id`)
            )",

            "CREATE TABLE IF NOT EXISTS `matching_schedules` (
              `id` INTEGER PRIMARY KEY AUTOINCREMENT,
              `user_id` INT NOT NULL,
              `slab_amount` DECIMAL(15, 2) NOT NULL,
              `daily_income` DECIMAL(15, 2) NOT NULL,
              `days_passed` INT DEFAULT 0,
              `max_days` INT DEFAULT 100,
              `status` VARCHAR(20) DEFAULT 'active',
              `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
            )",

            "INSERT INTO `packages` (`id`, `name`, `amount`) VALUES
            (1, 'Package $25', 25.00),
            (2, 'Package $50', 50.00),
            (3, 'Package $100', 100.00),
            (4, 'Package $250', 250.00),
            (5, 'Package $500', 500.00),
            (6, 'Package $1000', 1000.00),
            (7, 'Package $2500', 2500.00),
            (8, 'Package $5000', 5000.00),
            (9, 'Package $10000', 10000.00),
            (10, 'Package $25000', 25000.00),
            (11, 'Package $50000', 50000.00),
            (12, 'Package $100000', 100000.00),
            (13, 'Package $250000', 250000.00),
            (14, 'Package $500000', 500000.00),
            (15, 'Package $1000000', 1000000.00)",

            "INSERT INTO `ranks` (`id`, `name`, `matching_business`, `daily_income`, `duration_days`, `total_cap_multiplier`) VALUES
            (1, 'Mentor', 500.00, 0.25, 100, 3.00),
            (2, 'Pioneer', 1000.00, 2.50, 100, 3.00),
            (3, 'Elite', 2500.00, 6.25, 100, 3.00),
            (4, 'Titan', 5000.00, 12.50, 100, 3.00),
            (5, 'Master', 10000.00, 25.00, 100, 3.00),
            (6, 'Grand Master', 25000.00, 62.50, 100, 3.00),
            (7, 'Icon', 50000.00, 125.00, 100, 3.00),
            (8, 'Legend', 100000.00, 250.00, 100, 3.00),
            (9, 'Director', 250000.00, 625.00, 100, 3.00),
            (10, 'Ambassador', 500000.00, 1250.00, 100, 3.00),
            (11, 'Chairman', 1000000.00, 4000.00, 100, 3.00),
            (12, 'President', 2500000.00, 10000.00, 100, 3.00)",

            "INSERT INTO `admins` (`id`, `username`, `email`, `password`) VALUES
            (1, 'superadmin', 'admin@mlm.com', '\$2y\$10\$zprF16ZAl9c6GLhYrCxSqulSpN1D.fI0NAh5EUkL0MTfd58mg7Uyy')",

            "INSERT INTO `users` (`id`, `mid`, `username`, `full_name`, `phone`, `address`, `post_office_number`, `state`, `country`, `email`, `password`, `sponsor_id`, `placement_id`, `position`) VALUES
            (1, 'OPT59655', 'admin', 'Optimus Developer', '+15551234', '1200 N Federal Hwy Suite 300', '33432', 'Florida', 'United States of America', 'admin@example.com', '\$2y\$10\$G3GMptfbJd4LAeC1l0GP4OoZpk09W/vUax70EpI5PcrX1r8wyWVRC', NULL, NULL, NULL)"
        ];

        foreach ($queries as $q) {
            $this->connection->exec($q);
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
