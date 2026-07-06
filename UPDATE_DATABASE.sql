-- SQL Script to update existing database structure

-- 1. Update the transactions table
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
