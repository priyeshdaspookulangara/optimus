# Software Requirements Specification (SRS)
## MLM System Mobile Application & Cloud Infrastructure Architecture

---

### Document Control
- **Document Title:** Software Requirements Specification (SRS) for MLM Mobile Application & Cloud Backend
- **Target Audience:** Software Project Leader, Lead Cloud Architect, Mobile Developers (iOS/Android/Flutter/React Native), Backend Engineers
- **Version:** 1.0.0
- **Status:** Approved / Ready for Architecture Implementation

---

## 1. Executive Summary & Project Scope

### 1.1 Overview
This document specifies the software requirements, architecture guidelines, API specifications, database design, and cloud backend strategy for developing a cross-platform Mobile Application (iOS & Android) and cloud-native backend infrastructure for the Multi-Level Marketing (MLM) enterprise platform.

The system powers a high-volume financial MLM ecosystem featuring:
1. **Unilevel Referral Hierarchy (12-Generation Commission Distribution)**
2. **Binary Placement & Dynamic Leg Business Volume Tracking**
3. **Sequential Slab-Matching & Rank Qualification Engine**
4. **Daily ROI Engine (0.50% Daily ROI with 200% ROI Cap)**
5. **Propagated Rank Income & Daily Contract Schedule Payouts**
6. **Prepaid Activation PIN System (Paid and Free PIN Tiers)**
7. **TRC20 / ERC20 Cryptocurrency E-Wallet & Withdrawal Engine**
8. **Real-time Visual Genealogy Trees (Interactive Placement & Sponsor Views)**

### 1.2 Objectives for the Cloud & Mobile Solution
- **Mobile First Experience:** Deliver high-performance, native/cross-platform mobile apps (Flutter or React Native) with biometrics, instant push notifications, interactive genealogy trees, and seamless wallet operations.
- **Cloud-Native & Highly Scalable:** Migrate from legacy synchronous monolithic PHP execution to a serverless/containerized cloud architecture capable of handling millions of concurrent users and processing batch calculation tasks across massive genealogy trees in minutes.
- **Transactional Integrity & Financial Auditing:** Complete transaction safety with automated recalculation, chronological audit ledgers, and zero race conditions.

---

## 2. System Architecture & Cloud Infrastructure Strategy

### 2.1 Recommended Cloud Architecture Stack (AWS / GCP / Azure)

```
                       ┌──────────────────────────────────────┐
                       │   Mobile App (Flutter / React Native)│
                       │     (iOS / Android + Biometrics)     │
                       └──────────────────┬───────────────────┘
                                          │ HTTPS / WSS / gRPC
                                          ▼
                       ┌──────────────────────────────────────┐
                       │  Cloud API Gateway / CDN (Cloudflare) │
                       │    (DDoS Protection & Rate Limiting) │
                       └──────────────────┬───────────────────┘
                                          │
                  ┌───────────────────────┴───────────────────────┐
                  ▼                                               ▼
   ┌─────────────────────────────┐                 ┌─────────────────────────────┐
   │ REST / GraphQL Auth Service │                 │ Real-Time Push Notification │
   │ (AWS Lambda / Cloud Run /   │                 │ Service (FCM / APNs)        │
   │  ECS Fargate Microservices) │                 └─────────────────────────────┘
   └──────────────┬──────────────┘
                  │
 ┌────────────────┼─────────────────────────┬─────────────────────────┐
 │                │                         │                         │
 ▼                ▼                         ▼                         ▼
┌──────────────┐ ┌──────────────────────┐ ┌──────────────────────┐ ┌──────────────────────┐
│ Managed Rel. │ │ Distributed In-Memory│ │ Asynchronous Message │ │ Cloud Scheduled Cron │
│ Database     │ │ Cache                │ │ Queue                │ │ Task / Serverless    │
│ (AWS Aurora /│ │ (Redis Cluster /     │ │ (AWS SQS / RabbitMQ /│ │ Batch Engine         │
│ GCP Cloud    │ │  ElastiCache)        │ │  Kafka)              │ │ (EventBridge / AWS   │
│ SQL MySQL 8) │ │                      │ │                      │ │  Batch / Cloud Run)  │
└──────────────┘ └──────────────────────┘ └──────────────────────┘ └──────────────────────┘
```

### 2.2 Cloud Architecture Components

| Component | Cloud Solution Option (AWS) | Cloud Solution Option (GCP) | Description |
|---|---|---|---|
| **API Gateway** | AWS API Gateway / Kong | GCP Cloud API Gateway / Apigee | Endpoints management, OAuth2/JWT verification, rate limiting |
| **Compute / Backend Services** | ECS Fargate / AWS Lambda (Node.js/Go/Python) | Cloud Run / Cloud Functions | Microservices for User Management, Wallet, Pins, and Trees |
| **Database Engine** | AWS Aurora MySQL / RDS MySQL | GCP Cloud SQL for MySQL 8.0 | Primary relational database store with Read Replicas |
| **Caching Layer** | ElastiCache for Redis Cluster | MemoryStore for Redis | User sessions, live tree nodes, unilevel caching |
| **Asynchronous Queue** | AWS SQS / SNS | GCP Pub/Sub | Offloading level payouts, rank calculations, transaction logs |
| **Batch Job / Daily Engine** | EventBridge + AWS Batch / Fargate | Cloud Scheduler + Cloud Run Job | Executes daily ROI and Rank Income payouts at 05:00 AM |
| **File / Media Storage** | AWS S3 + CloudFront CDN | Cloud Storage + Cloud CDN | Profile avatars, app assets, proof of payment receipts |
| **Push Notifications** | Firebase Cloud Messaging (FCM) & Apple Push Notification service (APNs) | Real-time user notifications for earnings, withdrawals, and updates |

---

## 3. Data Schema & Entities Specification

The MySQL 8.0 relational schema forms the core source of truth. The cloud solution must preserve or enhance these table structures with proper indexing and partition keys.

### 3.1 Primary Schema Definitions

```sql
-- Users Entity
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `mid` VARCHAR(50) UNIQUE DEFAULT NULL,
  `username` VARCHAR(50) UNIQUE NOT NULL,
  `full_name` VARCHAR(100) DEFAULT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `post_office_number` VARCHAR(20) DEFAULT NULL,
  `state` VARCHAR(100) DEFAULT NULL,
  `country` VARCHAR(100) DEFAULT NULL,
  `email` VARCHAR(100) NOT NULL UNIQUE,
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
  FOREIGN KEY (`sponsor_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`placement_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
);

-- Packages Definition
CREATE TABLE IF NOT EXISTS `packages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL,
  `amount` DECIMAL(15, 2) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Ranks Tiers & Slab Definitions
CREATE TABLE IF NOT EXISTS `ranks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL,
  `matching_business` DECIMAL(15, 2) NOT NULL,
  `daily_income` DECIMAL(15, 2) NOT NULL,
  `duration_days` INT NOT NULL DEFAULT 100,
  `total_cap_multiplier` DECIMAL(5, 2) NOT NULL DEFAULT 3.0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Investments Ledger
CREATE TABLE IF NOT EXISTS `investments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `package_id` INT NOT NULL,
  `pin_id` INT DEFAULT NULL,
  `pin_type` ENUM('paid', 'free') DEFAULT 'paid',
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

-- General Ledger & Transactions
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
  `roi_date` DATE DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
  FOREIGN KEY (`related_user_id`) REFERENCES `users`(`id`)
);

-- Genealogy Unilevel Tree (Indexed up to 12 generations)
CREATE TABLE IF NOT EXISTS `genealogy` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `parent_id` INT NOT NULL,
  `level` INT NOT NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
  FOREIGN KEY (`parent_id`) REFERENCES `users`(`id`),
  UNIQUE KEY `user_parent_level_unique` (`user_id`, `parent_id`, `level`)
);

-- Prepaid Activation PINs
CREATE TABLE IF NOT EXISTS `pins` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `pin_code` VARCHAR(20) UNIQUE NOT NULL,
  `package_id` INT NOT NULL,
  `pin_type` ENUM('paid', 'free') DEFAULT 'paid',
  `status` ENUM('unused', 'used') DEFAULT 'unused',
  `assigned_to` INT DEFAULT NULL,
  `used_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`package_id`) REFERENCES `packages`(`id`),
  FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`),
  FOREIGN KEY (`used_by`) REFERENCES `users`(`id`)
);

-- Slab Matching Contracts / Schedules
CREATE TABLE IF NOT EXISTS `matching_schedules` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `slab_amount` DECIMAL(15, 2) NOT NULL,
  `daily_income` DECIMAL(15, 2) NOT NULL,
  `days_passed` INT DEFAULT 0,
  `max_days` INT DEFAULT 100,
  `status` ENUM('active', 'completed') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
);

-- Crypto Wallets
CREATE TABLE IF NOT EXISTS `user_wallets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `network` VARCHAR(50) DEFAULT 'TRC20',
  `address` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
);
```

---

## 4. Business Logic & Core Calculation Algorithms

### 4.1 05:00 AM Daily Calculation Cutoff Threshold
- All daily automated earnings (ROI, Rank Income, Matching Contracts) are bound to a strict daily cutoff: **05:00 AM**.
- **Investment Payout Rule:**
  - Investments created **before 05:00 AM** receive their initial daily payout on the morning of the same day.
  - Investments created **on or after 05:00 AM** begin receiving payouts on the following morning's 05:00 AM cycle.
- The system checks whether the evaluation current time is before or after 05:00 AM when evaluating expected vs. actual paid days.

### 4.2 Packages & Level Commissions (Unilevel Engine)
- **Available Tiers ($):** 25, 50, 100, 250, 500, 1,000, 2,500, 5,000, 10,000, 25,000, 50,000, 100,000, 250,000, 500,000, 1,000,000.
- **Level Percentages (12 Generations):**
  - Level 1: 10%
  - Level 2: 5%
  - Level 3: 3%
  - Level 4: 2%
  - Level 5 to 12: 1% each
- When a downline member activates an investment, commissions propagate upward through the `genealogy` table up to 12 levels using `SELECT DISTINCT parent_id, level` to prevent duplicate payouts.

### 4.3 Sequential Slab-Matching & Rank Qualifications
- Rank qualification relies on matching unilevel leg volumes:
  $$\text{Power Leg Raw} = \max(\text{Leg}_1, \text{Leg}_2, \dots, \text{Leg}_n)$$
  $$\text{Rest Leg Raw} = \sum(\text{Legs}) - \text{Power Leg Raw}$$
- The matching engine pairs Power Leg vs. Weaker Leg using descending sequential slabs: **$500,000, $250,000, $100,000, $50,000, $25,000, $10,000, $5,000, $2,500, $1,000, $500**.
- Each paired slab generates a 100-day matching contract (`matching_schedules`) with daily payouts:
  - $500 Slab $\rightarrow$ $0.25 / day (Mentor)
  - $1,000 Slab $\rightarrow$ $2.50 / day (Pioneer)
  - $2,500 Slab $\rightarrow$ $6.25 / day (Elite)
  - $5,000 Slab $\rightarrow$ $12.50 / day (Titan)
  - $10,000 Slab $\rightarrow$ $25.00 / day (Master)
  - $25,000 Slab $\rightarrow$ $62.50 / day (Grand Master)
  - $50,000 Slab $\rightarrow$ $125.00 / day (Icon)
  - $100,000 Slab $\rightarrow$ $250.00 / day (Legend)
  - $250,000 Slab $\rightarrow$ $625.00 / day (Director)
  - $500,000 Slab $\rightarrow$ $1,250.00 / day (Ambassador)
  - $1,000,000 Slab $\rightarrow$ $4,000.00 / day (Chairman)
  - $2,500,000 Slab $\rightarrow$ $10,000.00 / day (President)

### 4.4 Consecutive Single Referral Node Propagation Cap
- When an active matching schedule pays daily Rank Income to a earner, the same dollar amount propagates upward to active direct referrers in the `genealogy` tree.
- **Cap Rule:** Propagated Rank Income passes upward through active referrers, but **stops immediately** if it encounters **more than 3 consecutive single-referral nodes** (i.e. referrers who have only 1 direct sponsored child). Upward propagation is capped at a maximum of 3 consecutive single-node referrers.

### 4.5 Financial Earnings, Balance & 300% Cap Calculation
- **Total Earnings (`total_earning`):** Computed as the aggregate sum of `total_roi + total_level + total_rank`.
- **Total Withdrawals (`total_withdrawn`):** Calculated using `SUM(ABS(net_amount))` for all `WITHDRAWAL` transaction types (which includes requested amount + 5% fee).
- **Available Wallet Balance:**
  $$\text{Available Balance} = \sum_{\text{type} \neq \text{'INVESTMENT'}} (\text{net\_amount})$$
  *(Note: Package purchases labeled `type = 'INVESTMENT'` are excluded from withdrawable income balance calculations).*
- **Maximum Withdrawable:**
  $$\text{Max Withdrawable} = \frac{\text{Total Available Balance}}{1.05}$$
- **300% ID Cap Limit Rule:**
  - Maximum allowable total lifetime earnings across ROI, Level Income, and Rank Income is capped at $3.0 \times \text{Total Active Investment}$.
  - Allowable transaction amount: $\min(\text{Payout Amount}, \text{Max Cap} - \text{Total Earned})$.

---

## 5. Mobile Application Feature Specifications

The mobile app must deliver a premium, dark/gold themed user experience with fluid interactions, native security, and offline resilience.

### 5.1 Authentication & Profile Security
- **Biometric Login:** FaceID / Fingerprint unlocking after initial JWT authentication.
- **Sponsor & Registration Flow:**
  - Dynamic QR code scanning and direct deep linking (`/register?ref=OPT12345&pos=left`).
  - Auto-lookup of Sponsor Name & MID via live API endpoint.
  - Position selection (`left` / `right`) and required Activation PIN validation.
- **Wallet & Address Guard:** Secure PIN or biometric confirmation for updating TRC20/ERC20 wallet addresses.

### 5.2 Mobile Dashboard
- **Total Lifetime Earnings:** Overview cards displaying total earnings, ROI income, Level income, Rank income.
- **Earnings Composition Modal / Sheet:** Modal detailing Total Earnings, Total Withdrawals, and Effective Net Balance.
- **Today's ROI Card:** Dynamic daily ROI earnings calculated strictly based on today's `roi_date` or `created_at`.
- **Active Investment & Current Rank Badges:** Visual indicators of user rank (Mentor to President) and active package tier.
- **Quick Actions:** Invest with PIN, Direct Withdrawal, Share Referral Link, View Tree.

### 5.3 Interactive Visual Genealogy & Binary Placement Trees
- **Visual Nodes:** Nodes displaying MID, Username, Investment Tier, Rank, and Position.
- **Touch Controls:** Pinch-to-zoom, pan navigation, search member by MID/Username, dynamic level expansion (1-10 levels).
- **Bottom Nodes Finder:** Dedicated screen to immediately trace down to the bottommost left node and bottommost right node along with breadcrumb paths.

### 5.4 PIN Management & Activation
- **PIN Inventory:** Tabs for Unused vs. Used PINs, filterable by Paid PINs and Free PINs.
- **Activation Flow:** Input or select an unused PIN code to activate a package instantly without e-wallet deductions.
- **Instant Sharing:** Share unused activation PINs directly via WhatsApp, SMS, or system share sheets with pre-formatted text messages.

### 5.5 Financial Ledger & Reports
- **Daily ROI Payouts Ledger:** Itemized list showing exact calculated `roi_date`, package amount, and daily credit.
- **Level Income Ledger:** Detailed logs showing source member MID, level depth, and modal tree preview tracing the upline path up to the logged-in user.
- **Withdrawal Engine:** Input withdrawal amount (min $25.00), automatically display calculated 5% fee and net wallet deduction, and submit with real-time status tracking.

---

## 6. Mobile Application RESTful / GraphQL API Specifications

All endpoints require SSL/TLS (HTTPS) with JWT Bearer token authentication in header: `Authorization: Bearer <JWT_TOKEN>`.

### 6.1 Authentication & User Endpoints

#### `POST /api/v1/auth/register`
- **Request Body:**
```json
{
  "username": "johndoe",
  "email": "john@example.com",
  "password": "StrongPassword123!",
  "full_name": "John Doe",
  "phone": "+1234567890",
  "sponsor_code": "OPT59655",
  "position": "left",
  "activation_pin": "PIN-892314-X"
}
```
- **Response (201 Created):**
```json
{
  "success": true,
  "message": "User registered successfully",
  "data": {
    "user_id": 142,
    "mid": "OPT98123",
    "token": "eyJhbGciOiJIUzI1Ni..."
  }
}
```

#### `GET /api/v1/user/profile`
- **Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "id": 142,
    "mid": "OPT98123",
    "username": "johndoe",
    "email": "john@example.com",
    "rank_name": "Pioneer",
    "total_investment": 1000.00,
    "left_leg_business": 5000.00,
    "right_leg_business": 2500.00,
    "available_balance": 350.00,
    "max_withdrawable": 333.33
  }
}
```

### 6.2 Genealogy & Tree Endpoints

#### `GET /api/v1/tree/binary?mid=OPT98123&depth=5`
- **Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "id": 142,
    "mid": "OPT98123",
    "username": "johndoe",
    "rank": "Pioneer",
    "investment": 1000.00,
    "position": "root",
    "left": {
      "id": 145,
      "mid": "OPT98155",
      "username": "alice",
      "rank": "Mentor",
      "investment": 500.00,
      "position": "left",
      "left": null,
      "right": null
    },
    "right": {
      "id": 148,
      "mid": "OPT98199",
      "username": "bob",
      "rank": "None",
      "investment": 100.00,
      "position": "right",
      "left": null,
      "right": null
    }
  }
}
```

#### `GET /api/v1/tree/bottom-nodes?mid=OPT98123`
- **Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "bottom_left": {
      "id": 310,
      "mid": "OPT99100",
      "username": "bottom_left_user",
      "depth_level": 8,
      "path_breadcrumbs": ["johndoe", "alice", "sub_user_1", "bottom_left_user"]
    },
    "bottom_right": {
      "id": 340,
      "mid": "OPT99250",
      "username": "bottom_right_user",
      "depth_level": 6,
      "path_breadcrumbs": ["johndoe", "bob", "sub_user_2", "bottom_right_user"]
    }
  }
}
```

### 6.3 Financial & Investment Endpoints

#### `POST /api/v1/investments/activate-pin`
- **Request Body:**
```json
{
  "pin_code": "PIN-5000-XYZ"
}
```
- **Response (200 OK):**
```json
{
  "success": true,
  "message": "Package $5000 activated successfully via PIN",
  "data": {
    "investment_id": 89,
    "package_amount": 5000.00,
    "activated_at": "2025-02-28 14:30:00"
  }
}
```

#### `POST /api/v1/wallet/withdraw`
- **Request Body:**
```json
{
  "amount": 100.00,
  "wallet_address": "T9xZ8...TRC20Address"
}
```
- **Response (200 OK):**
```json
{
  "success": true,
  "message": "Withdrawal request submitted successfully",
  "data": {
    "requested_amount": 100.00,
    "fee_amount": 5.00,
    "net_deduction": 105.00,
    "status": "pending"
  }
}
```

---

## 7. Cloud Scalability, Batch Processing & Maintenance Strategy

### 7.1 Automated Daily Batch Engines (05:00 AM Cron)
1. **Parallel Execution via Distributed Locks:**
   - Use Redis distributed lock (`redlock`) to guarantee that only one batch worker executes daily ROI and Rank Income jobs at 05:00 AM.
2. **Chunking & Distributed Queue Processing:**
   - Divide millions of active investments into chunks of 1,000 records.
   - Push chunk messages onto SQS/PubSub queues for parallel serverless workers (AWS Lambda / Cloud Run) to evaluate ROI, check 200% ROI cap, and log `transactions`.
3. **Optimized Genealogy Tree Traversal:**
   - Store the pre-calculated unilevel hierarchy in the `genealogy` table.
   - Utilize Redis memory cache for reading ancestor paths during high-frequency investment activations to maintain sub-100ms API response times.

### 7.2 Security & Compliance Guidelines
- **Encryption in Transit & at Rest:** TLS 1.3 for all REST/WebSocket connections; AES-256 database storage encryption.
- **Rate Limiting & WAF:** Web Application Firewall (AWS WAF / Cloudflare) shielding against DDOS, SQL injection, and brute-force registration/login attempts.
- **Transaction Atomicity:** Strict SQL transactions (`START TRANSACTION` ... `COMMIT` / `ROLLBACK`) on all financial credits, matching schedules, and PIN activations.

---

## 8. Implementation Roadmap & Project Leader Action Items

### Phase 1: Cloud Infrastructure Setup & API Microservices (Weeks 1-3)
- Provision Managed MySQL (Aurora/Cloud SQL) with `database.sql` schema.
- Setup Redis Cluster for distributed locks and genealogy caching.
- Build JWT authentication, user profile, and activation PIN endpoints.

### Phase 2: Core MLM Engine & Batch Pipeline Implementation (Weeks 4-6)
- Implement 12-generation Unilevel distribution service.
- Implement Sequential Slab-Matching & Rank qualification algorithms.
- Deploy Cloud Scheduler / EventBridge trigger for the 05:00 AM Daily Calculation engine.

### Phase 3: Mobile App Development & Biometrics (Weeks 7-10)
- Develop Mobile UI/UX in Flutter or React Native using the purple (`#3f2259`) and gold (`#cca354`) visual theme.
- Implement interactive SVG/Canvas binary and sponsor genealogy trees.
- Integrate biometric local authentication and push notifications via FCM/APNs.

### Phase 4: Security Audits, Load Testing & Launch (Weeks 11-12)
- Perform concurrency load testing on daily 05:00 AM batch payouts.
- Conduct penetration testing on wallet withdrawals and PIN activations.
- Submit iOS App Store and Google Play Store builds.

---
*End of Specification Document.*
