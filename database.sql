-- MLM Database Schema

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) UNIQUE NOT NULL,
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
