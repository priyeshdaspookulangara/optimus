# MLM Application

## Overview
This is a robust, database-driven Multi-Level Marketing (MLM) application developed in plain PHP.

## Core Features
- **Recursive Genealogy Engine**: Handles 12-generation referral tree.
- **ROI Engine**: 0.50% daily ROI with a max cap of 200%.
- **Matching Engine**: Identifies Power Leg and Matching Leg for daily rank income.
- **Total ID Cap**: 300% cap on all earnings.
- **Withdrawal System**: Minimum $25 withdrawal with a flat $10 gas fee.

## Configuration
All business variables are stored in `includes/config.php`.

### Updating Parameters
- **Packages**: Modify the `packages` array in `config.php`.
- **Level Percentages**: Update the `level_percentages` array (keys 1-12).
- **Ranks**: Adjust rank names, matching business requirements, and daily income in the `ranks` array.
- **ROI & Caps**: Change rates and multipliers in their respective sections in `config.php`.

## Security
- Uses PDO with prepared statements for all queries.
- Inputs are sanitized to prevent SQL injection.

## Setup
1. Import `database.sql` into your MySQL database.
2. Configure database credentials in `includes/config.php`.
3. Set up a cron job to run the following daily logic:
   ```php
   $engine = new MLMEngine();
   $engine->processDailyROI();
   $engine->processRankIncome();
   ```

## Usage
### 1. Registration
Go to `register.php` to create a new user. You can optionally provide a `Sponsor ID` and `Placement ID` to build the genealogy tree.

**Root Member:**
The system identifies a "Root Member" as any user with no `sponsor_id`. In the provided data, `admin` is the initial root. To add a new primary root, manually insert a user into the `users` table via SQL with `sponsor_id` and `placement_id` set to `NULL`.

### 2. Login
Use the credentials created during registration at `login.php`.

**Default Credentials (after seeding):**
- **Username:** `admin`
- **Password:** `password123`

## Admin Panel
The application includes a comprehensive Super Admin panel for managing the entire system.
- **URL:** `/admin/login.php`
- **Default Username:** `superadmin`
- **Default Password:** `admin123`

### Admin Features:
- **Member Management:** List, search, activate, or suspend members.
- **Package Management:** Add or update investment tiers.
- **PIN Management:** Generate activation PINs for offline sales or manual activations.
- **Business Reports:** Track total payouts (ROI, Level, Rank) and daily business volume.

### 3. Investment
In the dashboard (or via a direct script call to `MLMEngine::createInvestment`), a user can purchase a package. This triggers:
- Upline level commissions (12 levels).
- Binary business volume updates for the placement tree.

### 4. Wallet & Withdrawals
- Users can deposit USDT via the E-Wallet section.
- Withdrawals require a minimum of $25 and incur a $10 flat fee.

## ROI & Rank Income Automation
ROI and Rank income calculations occur daily. To automate this, set up a cron job to run the `cron.php` script once every 24 hours.

**Example Cron Job (at midnight):**
```bash
0 0 * * * /usr/bin/php /path/to/your/app/cron.php >> /path/to/your/app/cron.log 2>&1
```
