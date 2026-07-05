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
