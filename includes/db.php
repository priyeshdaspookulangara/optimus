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
            // Fall back to local SQLite database
            $sqlitePath = __DIR__ . "/database.sqlite";
            try {
                $this->connection = new PDO("sqlite:" . $sqlitePath, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                $this->connection->exec("PRAGMA foreign_keys = ON;");

                // Check if schema is already loaded
                $stmt = $this->connection->query("SELECT name FROM sqlite_master WHERE type='table' AND name='users'");
                if (!$stmt->fetch()) {
                    // Create SQLite tables
                    $this->connection->exec("
                        CREATE TABLE IF NOT EXISTS `users` (
                          `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                          `mid` TEXT UNIQUE DEFAULT NULL,
                          `username` TEXT UNIQUE NOT NULL,
                          `full_name` TEXT DEFAULT NULL,
                          `phone` TEXT DEFAULT NULL,
                          `address` TEXT DEFAULT NULL,
                          `post_office_number` TEXT DEFAULT NULL,
                          `state` TEXT DEFAULT NULL,
                          `country` TEXT DEFAULT NULL,
                          `email` TEXT NOT NULL,
                          `password` TEXT NOT NULL,
                          `sponsor_id` INTEGER DEFAULT NULL,
                          `placement_id` INTEGER DEFAULT NULL,
                          `position` TEXT DEFAULT NULL,
                          `rank_id` INTEGER DEFAULT 0,
                          `total_investment` DECIMAL(15, 2) DEFAULT 0.00,
                          `left_leg_business` DECIMAL(15, 2) DEFAULT 0.00,
                          `right_leg_business` DECIMAL(15, 2) DEFAULT 0.00,
                          `rank_income_days` INTEGER DEFAULT 0,
                          `status` TEXT DEFAULT 'active',
                          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        );

                        CREATE TABLE IF NOT EXISTS `packages` (
                          `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                          `name` TEXT NOT NULL,
                          `amount` DECIMAL(15, 2) NOT NULL,
                          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        );

                        CREATE TABLE IF NOT EXISTS `ranks` (
                          `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                          `name` TEXT NOT NULL,
                          `matching_business` DECIMAL(15, 2) NOT NULL,
                          `daily_income` DECIMAL(15, 2) NOT NULL,
                          `duration_days` INTEGER NOT NULL,
                          `total_cap_multiplier` DECIMAL(5, 2) NOT NULL,
                          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        );

                        CREATE TABLE IF NOT EXISTS `investments` (
                          `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                          `user_id` INTEGER NOT NULL,
                          `package_id` INTEGER NOT NULL,
                          `amount` DECIMAL(15, 2) NOT NULL,
                          `roi_earned` DECIMAL(15, 2) DEFAULT 0.00,
                          `total_earned` DECIMAL(15, 2) DEFAULT 0.00,
                          `days_passed` INTEGER DEFAULT 0,
                          `last_roi_at` DATE DEFAULT NULL,
                          `status` TEXT DEFAULT 'active',
                          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        );

                        CREATE TABLE IF NOT EXISTS `transactions` (
                          `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                          `user_id` INTEGER NOT NULL,
                          `related_user_id` INTEGER DEFAULT NULL,
                          `investment_id` INTEGER DEFAULT NULL,
                          `level` INTEGER DEFAULT NULL,
                          `type` TEXT NOT NULL,
                          `amount` DECIMAL(15, 2) NOT NULL,
                          `fee` DECIMAL(15, 2) DEFAULT 0.00,
                          `net_amount` DECIMAL(15, 2) NOT NULL,
                          `description` TEXT,
                          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        );

                        CREATE TABLE IF NOT EXISTS `genealogy` (
                          `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                          `user_id` INTEGER NOT NULL,
                          `parent_id` INTEGER NOT NULL,
                          `level` INTEGER NOT NULL
                        );

                        CREATE TABLE IF NOT EXISTS `user_wallets` (
                          `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                          `user_id` INTEGER NOT NULL,
                          `network` TEXT DEFAULT 'TRC20',
                          `address` TEXT NOT NULL,
                          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        );

                        CREATE TABLE IF NOT EXISTS `admins` (
                          `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                          `username` TEXT UNIQUE NOT NULL,
                          `password` TEXT NOT NULL,
                          `email` TEXT UNIQUE NOT NULL,
                          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        );

                        CREATE TABLE IF NOT EXISTS `pins` (
                          `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                          `pin_code` TEXT UNIQUE NOT NULL,
                          `package_id` INTEGER NOT NULL,
                          `status` TEXT DEFAULT 'unused',
                          `assigned_to` INTEGER DEFAULT NULL,
                          `used_by` INTEGER DEFAULT NULL,
                          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        );

                        CREATE TABLE IF NOT EXISTS `matching_schedules` (
                          `id` INTEGER PRIMARY KEY AUTOINCREMENT,
                          `user_id` INTEGER NOT NULL,
                          `slab_amount` DECIMAL(15, 2) NOT NULL,
                          `daily_income` DECIMAL(15, 2) NOT NULL,
                          `days_passed` INTEGER DEFAULT 0,
                          `max_days` INTEGER DEFAULT 100,
                          `status` TEXT DEFAULT 'active',
                          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        );
                    ");

                    // Seed default data
                    $this->connection->exec("
                        INSERT INTO `packages` (`name`, `amount`) VALUES
                        ('Package $25', 25), ('Package $50', 50), ('Package $100', 100),
                        ('Package $250', 250), ('Package $500', 500), ('Package $1000', 1000),
                        ('Package $2500', 2500), ('Package $5000', 5000), ('Package $10000', 10000),
                        ('Package $25000', 25000), ('Package $50000', 50000), ('Package $100000', 100000),
                        ('Package $250000', 250000), ('Package $500000', 500000), ('Package $1000000', 1000000);

                        INSERT INTO `ranks` (`name`, `matching_business`, `daily_income`, `duration_days`, `total_cap_multiplier`) VALUES
                        ('Mentor', 500, 0.25, 100, 3.0),
                        ('Pioneer', 1000, 2.50, 100, 3.0),
                        ('Elite', 2500, 6.25, 100, 3.0),
                        ('Titan', 5000, 12.50, 100, 3.0),
                        ('Master', 10000, 25.00, 100, 3.0),
                        ('Grand Master', 25000, 62.50, 100, 3.0),
                        ('Icon', 50000, 125.00, 100, 3.0),
                        ('Legend', 100000, 250.00, 100, 3.0),
                        ('Director', 250000, 625.00, 100, 3.0),
                        ('Ambassador', 500000, 1250.00, 100, 3.0),
                        ('Chairman', 1000000, 4000.00, 100, 3.0),
                        ('President', 2500000, 10000.00, 100, 3.0);

                        INSERT INTO `users` (`mid`, `username`, `email`, `password`) VALUES
                        ('OPT59655', 'admin', 'admin@example.com', '\$2y\$10\$G3GMptfbJd4LAeC1l0GP4OoZpk09W/vUax70EpI5PcrX1r8wyWVRC');

                        INSERT INTO `admins` (`username`, `email`, `password`) VALUES
                        ('superadmin', 'admin@mlm.com', '\$2y\$10\$zprF16ZAl9c6GLhYrCxSqulSpN1D.fI0NAh5EUkL0MTfd58mg7Uyy');
                    ");
                }
            } catch (PDOException $se) {
                die("Connection failed: " . $e->getMessage() . " and SQLite fallback failed: " . $se->getMessage());
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
