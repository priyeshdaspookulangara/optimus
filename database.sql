-- MLM Database Schema

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) UNIQUE NOT NULL,
  `full_name` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `email` VARCHAR(100) UNIQUE NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `sponsor_id` INT DEFAULT NULL,
  `placement_id` INT DEFAULT NULL,
  `position` ENUM('left', 'right') DEFAULT NULL,
  `rank_id` INT DEFAULT 0,
  `total_investment` DECIMAL(15, 2) DEFAULT 0.00,
  `left_leg_business` DECIMAL(15, 2) DEFAULT 0.00,
  `right_leg_business` DECIMAL(15, 2) DEFAULT 0.00,
  `rank_income_days` INT DEFAULT 0,
  `status` ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`sponsor_id`) REFERENCES `users`(`id`),
  FOREIGN KEY (`placement_id`) REFERENCES `users`(`id`)
);

CREATE TABLE IF NOT EXISTS `packages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(15, 2) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `ranks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL,
  `matching_business` DECIMAL(15, 2) NOT NULL,
  `daily_income` DECIMAL(15, 2) NOT NULL,
  `duration_days` INT NOT NULL,
  `total_cap_multiplier` DECIMAL(5, 2) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `investments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `package_id` INT NOT NULL,
  `amount` DECIMAL(15, 2) NOT NULL,
  `roi_earned` DECIMAL(15, 2) DEFAULT 0.00,
  `total_earned` DECIMAL(15, 2) DEFAULT 0.00,
  `days_passed` INT DEFAULT 0,
  `last_roi_at` DATE DEFAULT NULL,
  `status` ENUM('active', 'completed', 'capped') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
  FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`)
);

CREATE TABLE IF NOT EXISTS `transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `related_user_id` INT DEFAULT NULL,
  `investment_id` INT DEFAULT NULL,
  `level` INT DEFAULT NULL,
  `type` ENUM('ROI', 'LEVEL_INCOME', 'RANK_INCOME', 'WITHDRAWAL', 'INVESTMENT', 'DEPOSIT') NOT NULL,
  `amount` DECIMAL(15, 2) NOT NULL,
  `fee` DECIMAL(15, 2) DEFAULT 0.00,
  `net_amount` DECIMAL(15, 2) NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
  FOREIGN KEY (`related_user_id`) REFERENCES `users`(`id`)
);

CREATE TABLE IF NOT EXISTS `genealogy` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `parent_id` INT NOT NULL,
  `level` INT NOT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
  FOREIGN KEY (`parent_id`) REFERENCES `users`(`id`)
);

CREATE TABLE IF NOT EXISTS `user_wallets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `network` VARCHAR(50) DEFAULT 'TRC20',
  `address` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
);

CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) UNIQUE NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `email` VARCHAR(100) UNIQUE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `pins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `pin_code` VARCHAR(20) UNIQUE NOT NULL,
  `package_id` INT NOT NULL,
  `status` ENUM('unused', 'used') DEFAULT 'unused',
  `assigned_to` INT DEFAULT NULL,
  `used_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`),
  FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`),
  FOREIGN KEY (`used_by`) REFERENCES `users`(`id`)
);

-- Initial Data Seed

-- Default Packages
INSERT INTO `packages` (`name`, `amount`) VALUES
('Package $25', 25), ('Package $50', 50), ('Package $100', 100),
('Package $250', 250), ('Package $500', 500), ('Package $1000', 1000),
('Package $2500', 2500), ('Package $5000', 5000), ('Package $10000', 10000),
('Package $25000', 25000), ('Package $50000', 50000), ('Package $100000', 100000),
('Package $250000', 250000), ('Package $500000', 500000), ('Package $1000000', 1000000);

-- Default Ranks
INSERT INTO `ranks` (`name`, `matching_business`, `daily_income`, `duration_days`, `total_cap_multiplier`) VALUES
('Mentor', 500, 2.00, 100, 3.0),
('Pioneer', 1000, 4.00, 100, 3.0),
('Elite', 2500, 10.00, 100, 3.0),
('Titan', 5000, 20.00, 100, 3.0),
('Master', 10000, 40.00, 100, 3.0),
('Grand Master', 25000, 100.00, 100, 3.0),
('Icon', 50000, 200.00, 100, 3.0),
('Legend', 100000, 400.00, 100, 3.0),
('Director', 250000, 1000.00, 100, 3.0),
('Ambassador', 500000, 2000.00, 100, 3.0),
('Chairman', 1000000, 4000.00, 100, 3.0),
('President', 2500000, 10000.00, 100, 3.0);

-- Default Sample User
-- Username: admin
-- Password: password123 (hashed)
INSERT INTO `users` (`username`, `email`, `password`) VALUES
('admin', 'admin@example.com', '$2y$10$G3GMptfbJd4LAeC1l0GP4OoZpk09W/vUax70EpI5PcrX1r8wyWVRC');

-- Default Super Admin
-- Username: superadmin
-- Password: adminpassword (hashed)
INSERT INTO `admins` (`username`, `email`, `password`) VALUES
('superadmin', 'admin@mlm.com', '$2y$10$6zK6Yn1u3x8pYlR0R7o9u.Fj8i3/vY7ZkC6mQW0R0K8R5L1h0k5pG');
