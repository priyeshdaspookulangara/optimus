-- SQL Script to update existing database structure

-- 1. Update the transactions table
ALTER TABLE `users`
  ADD COLUMN `full_name` VARCHAR(100) DEFAULT NULL AFTER `username`,
  ADD COLUMN `phone` VARCHAR(20) DEFAULT NULL AFTER `full_name`,
  ADD COLUMN `address` TEXT DEFAULT NULL AFTER `phone`,
  ADD COLUMN `post_office_number` VARCHAR(20) DEFAULT NULL AFTER `address`,
  ADD COLUMN `state` VARCHAR(100) DEFAULT NULL AFTER `post_office_number`,
  ADD COLUMN `country` VARCHAR(100) DEFAULT NULL AFTER `state`;

ALTER TABLE `transactions`
  ADD COLUMN `investment_id` INT DEFAULT NULL AFTER `related_user_id`,
  ADD COLUMN `level` INT DEFAULT NULL AFTER `investment_id`,
  MODIFY COLUMN `type` ENUM('ROI', 'LEVEL_INCOME', 'RANK_INCOME', 'WITHDRAWAL', 'INVESTMENT', 'DEPOSIT') NOT NULL;

-- 2. Create the missing user_wallets table
CREATE TABLE IF NOT EXISTS `user_wallets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `network` VARCHAR(50) DEFAULT 'TRC20',
  `address` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS `admins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) UNIQUE NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `email` VARCHAR(100) UNIQUE NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Insert default superadmin if not exists
INSERT IGNORE INTO `admins` (`username`, `email`, `password`) VALUES
('superadmin', 'admin@mlm.com', '$2y$10$zprF16ZAl9c6GLhYrCxSqulSpN1D.fI0NAh5EUkL0MTfd58mg7Uyy');
