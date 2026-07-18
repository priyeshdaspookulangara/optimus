-- MLM Initial Data Seed DML

-- 1. Insert Default Packages ($25 up to $1,000,000)
TRUNCATE TABLE `packages`;
INSERT INTO `packages` (`id`, `name`, `amount`) VALUES
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
(15, 'Package $1000000', 1000000.00);

-- 2. Insert Default 12 Specific Ranks
TRUNCATE TABLE `ranks`;
INSERT INTO `ranks` (`id`, `name`, `matching_business`, `daily_income`, `duration_days`, `total_cap_multiplier`) VALUES
(1, 'Mentor', 500.00, 2.00, 100, 3.00),
(2, 'Pioneer', 1000.00, 4.00, 100, 3.00),
(3, 'Elite', 2500.00, 10.00, 100, 3.00),
(4, 'Titan', 5000.00, 20.00, 100, 3.00),
(5, 'Master', 10000.00, 40.00, 100, 3.00),
(6, 'Grand Master', 25000.00, 100.00, 100, 3.00),
(7, 'Icon', 50000.00, 200.00, 100, 3.00),
(8, 'Legend', 100000.00, 400.00, 100, 3.00),
(9, 'Director', 250000.00, 1000.00, 100, 3.00),
(10, 'Ambassador', 500000.00, 2000.00, 100, 3.00),
(11, 'Chairman', 1000000.00, 4000.00, 100, 3.00),
(12, 'President', 2500000.00, 10000.00, 100, 3.00);

-- 3. Insert Default Super Admin
-- Username: superadmin
-- Password: adminpassword (hashed)
TRUNCATE TABLE `admins`;
INSERT INTO `admins` (`id`, `username`, `email`, `password`) VALUES
(1, 'superadmin', 'admin@mlm.com', '$2y$10$6zK6Yn1u3x8pYlR0R7o9u.Fj8i3/vY7ZkC6mQW0R0K8R5L1h0k5pG');

-- 4. Insert Default Seed Member
-- Username: admin
-- Password: password123 (hashed)
-- Includes default values for physical profile columns
DELETE FROM `users` WHERE `username` = 'admin';
INSERT INTO `users` (`id`, `username`, `full_name`, `phone`, `address`, `post_office_number`, `state`, `country`, `email`, `password`, `sponsor_id`, `placement_id`, `position`) VALUES
(1, 'admin', 'Optimus Developer', '+15551234', '1200 N Federal Hwy Suite 300', '33432', 'Florida', 'United States of America', 'admin@example.com', '$2y$10$G3GMptfbJd4LAeC1l0GP4OoZpk09W/vUax70EpI5PcrX1r8wyWVRC', NULL, NULL, NULL);
