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
            // Fallback to SQLite for local development and testing
            try {
                $sqlite_file = __DIR__ . '/../database.sqlite';
                $this->connection = new PDO("sqlite:" . $sqlite_file);
                $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                // Create SQLite tables if they do not exist
                $this->connection->exec("CREATE TABLE IF NOT EXISTS `users` (
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
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");

                $this->connection->exec("CREATE TABLE IF NOT EXISTS `packages` (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `name` VARCHAR(50) NOT NULL,
                    `amount` DECIMAL(15, 2) NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");

                $this->connection->exec("CREATE TABLE IF NOT EXISTS `ranks` (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `name` VARCHAR(50) NOT NULL,
                    `matching_business` DECIMAL(15, 2) NOT NULL,
                    `daily_income` DECIMAL(15, 2) NOT NULL,
                    `duration_days` INT NOT NULL,
                    `total_cap_multiplier` DECIMAL(5, 2) NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");

                $this->connection->exec("CREATE TABLE IF NOT EXISTS `investments` (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `user_id` INT NOT NULL,
                    `package_id` INT NOT NULL,
                    `amount` DECIMAL(15, 2) NOT NULL,
                    `roi_earned` DECIMAL(15, 2) DEFAULT 0.00,
                    `total_earned` DECIMAL(15, 2) DEFAULT 0.00,
                    `days_passed` INT DEFAULT 0,
                    `last_roi_at` DATE DEFAULT NULL,
                    `status` VARCHAR(20) DEFAULT 'active',
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");

                $this->connection->exec("CREATE TABLE IF NOT EXISTS `transactions` (
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
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");

                $this->connection->exec("CREATE TABLE IF NOT EXISTS `genealogy` (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `user_id` INT NOT NULL,
                    `parent_id` INT NOT NULL,
                    `level` INT NOT NULL
                )");

                $this->connection->exec("CREATE TABLE IF NOT EXISTS `user_wallets` (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `user_id` INT NOT NULL,
                    `network` VARCHAR(50) DEFAULT 'TRC20',
                    `address` VARCHAR(255) NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");

                $this->connection->exec("CREATE TABLE IF NOT EXISTS `admins` (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `username` VARCHAR(50) UNIQUE NOT NULL,
                    `password` VARCHAR(255) NOT NULL,
                    `email` VARCHAR(100) UNIQUE NOT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");

                $this->connection->exec("CREATE TABLE IF NOT EXISTS `pins` (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `pin_code` VARCHAR(20) UNIQUE NOT NULL,
                    `package_id` INT NOT NULL,
                    `status` VARCHAR(20) DEFAULT 'unused',
                    `assigned_to` INT DEFAULT NULL,
                    `used_by` INT DEFAULT NULL,
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");

                $this->connection->exec("CREATE TABLE IF NOT EXISTS `matching_schedules` (
                    `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                    `user_id` INT NOT NULL,
                    `slab_amount` DECIMAL(15, 2) NOT NULL,
                    `daily_income` DECIMAL(15, 2) NOT NULL,
                    `days_passed` INT DEFAULT 0,
                    `max_days` INT DEFAULT 100,
                    `status` VARCHAR(20) DEFAULT 'active',
                    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");

                // Populate seed data if database is empty
                $count = $this->connection->query("SELECT COUNT(*) FROM `users`")->fetchColumn();
                if ($count == 0) {
                    $this->connection->exec("INSERT INTO `users` (`id`, `username`, `email`, `password`) VALUES
                        (1, 'admin', 'admin@example.com', '\$2y\$10\$G3GMptfbJd4LAeC1l0GP4OoZpk09W/vUax70EpI5PcrX1r8wyWVRC')");
                }

                $admCount = $this->connection->query("SELECT COUNT(*) FROM `admins`")->fetchColumn();
                if ($admCount == 0) {
                    $this->connection->exec("INSERT INTO `admins` (`id`, `username`, `email`, `password`) VALUES
                        (1, 'superadmin', 'admin@mlm.com', '\$2y\$10\$zprF16ZAl9c6GLhYrCxSqulSpN1D.fI0NAh5EUkL0MTfd58mg7Uyy')");
                }

                $pkgCount = $this->connection->query("SELECT COUNT(*) FROM `packages`")->fetchColumn();
                if ($pkgCount == 0) {
                    $packages = [
                        ['Package $25', 25], ['Package $50', 50], ['Package $100', 100],
                        ['Package $250', 250], ['Package $500', 500], ['Package $1000', 1000],
                        ['Package $2500', 2500], ['Package $5000', 5000], ['Package $10000', 10000],
                        ['Package $25000', 25000], ['Package $50000', 50000], ['Package $100000', 100000],
                        ['Package $250000', 250000], ['Package $500000', 500000], ['Package $1000000', 1000000]
                    ];
                    $stmt = $this->connection->prepare("INSERT INTO `packages` (`name`, `amount`) VALUES (?, ?)");
                    foreach ($packages as $p) {
                        $stmt->execute($p);
                    }
                }

                $rankCount = $this->connection->query("SELECT COUNT(*) FROM `ranks`")->fetchColumn();
                if ($rankCount == 0) {
                    $ranks = [
                        ['Mentor', 500, 0.25, 100, 3.0],
                        ['Pioneer', 1000, 2.50, 100, 3.0],
                        ['Elite', 2500, 6.25, 100, 3.0],
                        ['Titan', 5000, 12.50, 100, 3.0],
                        ['Master', 10000, 25.00, 100, 3.0],
                        ['Grand Master', 25000, 62.50, 100, 3.0],
                        ['Icon', 50000, 125.00, 100, 3.0],
                        ['Legend', 100000, 250.00, 100, 3.0],
                        ['Director', 250000, 625.00, 100, 3.0],
                        ['Ambassador', 500000, 1250.00, 100, 3.0],
                        ['Chairman', 1000000, 4000.00, 100, 3.0],
                        ['President', 2500000, 10000.00, 100, 3.0]
                    ];
                    $stmt = $this->connection->prepare("INSERT INTO `ranks` (`name`, `matching_business`, `daily_income`, `duration_days`, `total_cap_multiplier`) VALUES (?, ?, ?, ?, ?)");
                    foreach ($ranks as $r) {
                        $stmt->execute($r);
                    }
                }
            } catch (Exception $e2) {
                die("Connection failed: " . $e->getMessage() . " and SQLite fallback failed: " . $e2->getMessage());
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
