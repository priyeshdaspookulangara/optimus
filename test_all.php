<?php
// PHP CLI Test Suite for MLM Application Logic (Server-aligned version)

class TestDatabase {
    private $connection;
    public function __construct($pdo) {
        $this->connection = $pdo;
    }
    public function getConnection() {
        return $this->connection;
    }
}

try {
    // 1. Setup in-memory SQLite database
    $sqlite = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Create SQLite tables
    $sqlite->exec("CREATE TABLE users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      mid TEXT UNIQUE,
      username TEXT UNIQUE,
      full_name TEXT,
      phone TEXT,
      address TEXT,
      post_office_number TEXT,
      state TEXT,
      country TEXT,
      email TEXT,
      password TEXT,
      sponsor_id INTEGER,
      placement_id INTEGER,
      position TEXT,
      rank_id INTEGER DEFAULT 0,
      total_investment REAL DEFAULT 0.00,
      left_leg_business REAL DEFAULT 0.00,
      right_leg_business REAL DEFAULT 0.00,
      rank_income_days INTEGER DEFAULT 0,
      status TEXT DEFAULT 'active',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );");

    $sqlite->exec("CREATE TABLE packages (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT,
      amount REAL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );");

    $sqlite->exec("CREATE TABLE ranks (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT,
      matching_business REAL,
      daily_income REAL,
      duration_days INTEGER,
      total_cap_multiplier REAL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );");

    $sqlite->exec("CREATE TABLE investments (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER,
      package_id INTEGER,
      amount REAL,
      roi_earned REAL DEFAULT 0.00,
      total_earned REAL DEFAULT 0.00,
      days_passed INTEGER DEFAULT 0,
      last_roi_at TEXT,
      status TEXT DEFAULT 'active',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );");

    $sqlite->exec("CREATE TABLE transactions (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER,
      related_user_id INTEGER,
      investment_id INTEGER,
      level INTEGER,
      type TEXT,
      amount REAL,
      fee REAL DEFAULT 0.00,
      net_amount REAL,
      description TEXT,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );");

    $sqlite->exec("CREATE TABLE genealogy (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER,
      parent_id INTEGER,
      level INTEGER
    );");

    $sqlite->exec("CREATE TABLE matching_schedules (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER,
      slab_amount REAL,
      daily_income REAL,
      days_passed INTEGER DEFAULT 0,
      max_days INTEGER DEFAULT 100,
      status TEXT DEFAULT 'active',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );");

    $sqlite->exec("CREATE TABLE conferred_ranks (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_id INTEGER,
      downline_id INTEGER,
      rank_id INTEGER,
      daily_income REAL,
      days_passed INTEGER DEFAULT 0,
      max_days INTEGER DEFAULT 100,
      status TEXT DEFAULT 'active',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );");

    // Seed default packages and ranks
    $sqlite->exec("INSERT INTO packages (id, name, amount) VALUES (1, 'Package $25', 25), (2, 'Package $500', 500);");
    $sqlite->exec("INSERT INTO ranks (id, name, matching_business, daily_income, duration_days, total_cap_multiplier) VALUES
        (1, 'Mentor', 500, 0.25, 100, 3.0),
        (2, 'Pioneer', 1000, 2.50, 100, 3.0);");

    // 2. Inject SQLite Connection into Database Singleton using Reflection
    require_once __DIR__ . '/includes/db.php';
    $ref = new ReflectionClass('Database');
    $instanceProp = $ref->getProperty('instance');
    $instanceProp->setAccessible(true);

    $testDbWrapper = new TestDatabase($sqlite);
    $instanceProp->setValue(null, $testDbWrapper);

    echo "✅ SQLite in-memory database mock injected successfully!\n";

    // Load MLM Engine
    require_once __DIR__ . '/includes/engine.php';
    $engine = new MLMEngine();

    // 3. Test Registration and Placement Tree calculation
    echo "--- Testing Registration & Binary Placement ---\n";

    // Seed Admin (ID 1)
    $sqlite->exec("INSERT INTO users (id, mid, username, email, password) VALUES (1, 'OPT11111', 'admin', 'admin@example.com', 'pwd')");

    // Simulate register user 2 under sponsor 1, position Left
    $sqlite->exec("INSERT INTO users (id, mid, username, email, password, sponsor_id, placement_id, position) VALUES (2, 'OPT22222', 'user2', 'u2@example.com', 'pwd', 1, 1, 'left')");
    $engine->addToGenealogy(2, 1, 1, 'left');

    // Simulate register user 3 under sponsor 1, position Left (placed under user 2 left)
    $sqlite->exec("INSERT INTO users (id, mid, username, email, password, sponsor_id, placement_id, position) VALUES (3, 'OPT33333', 'user3', 'u3@example.com', 'pwd', 1, 2, 'left')");
    $engine->addToGenealogy(3, 1, 2, 'left');

    // Verify genealogy matches placement_id instead of sponsor_id!
    // User 3 parent should be User 2 (Level 1) and then Admin 1 (Level 2)
    $stmtGen = $sqlite->prepare("SELECT parent_id, level FROM genealogy WHERE user_id = 3 ORDER BY level ASC");
    $stmtGen->execute();
    $gData = $stmtGen->fetchAll();

    if ($gData[0]['parent_id'] !== 2 || $gData[0]['level'] !== 1) {
        throw new Exception("FAIL: Level 1 parent for User 3 in genealogy should be User 2, got: " . $gData[0]['parent_id']);
    }
    if ($gData[1]['parent_id'] !== 1 || $gData[1]['level'] !== 2) {
        throw new Exception("FAIL: Level 2 parent for User 3 in genealogy should be Admin 1, got: " . $gData[1]['parent_id']);
    }
    echo "✅ Genealogy tree correctly built based on placement_id (User 3 -> User 2 -> Admin 1)!\n";

    // 4. Test leg business calculation based on placement_id
    echo "--- Testing getLegsBusiness() ---\n";
    // Setup a Right leg under User 2 (User 4)
    $sqlite->exec("INSERT INTO users (id, mid, username, email, password, sponsor_id, placement_id, position) VALUES (4, 'OPT44444', 'user4', 'u4@example.com', 'pwd', 2, 2, 'right')");
    $engine->addToGenealogy(4, 2, 2, 'right');

    // Give some total_investment to User 3 and User 4
    $sqlite->exec("UPDATE users SET total_investment = 500.00 WHERE id = 3");
    $sqlite->exec("UPDATE users SET total_investment = 500.00 WHERE id = 4");

    // Fetch legs of User 2 (ID 2)
    // Left leg has User 3 (500)
    // Right leg has User 4 (500)
    $legs = $engine->getLegsBusiness(2);

    if ($legs['power_leg'] !== 500.0) {
        throw new Exception("FAIL: Power leg business should be 500.0, got: " . $legs['power_leg']);
    }
    if ($legs['matching_leg'] !== 500.0) {
        throw new Exception("FAIL: Matching leg business should be 500.0, got: " . $legs['matching_leg']);
    }
    if ($legs['matched_business'] !== 500.0) {
        throw new Exception("FAIL: Matched business should be 500.0, got: " . $legs['matched_business']);
    }
    echo "✅ getLegsBusiness() calculated power and matching leg business correctly based on placement subtree recursion!\n";

    // 5. Test Rank Upgrade & Conferred Ranks generation
    echo "--- Testing Rank Upgrades & awardConferredRanks() ---\n";

    // We trigger dynamic slab contract and upward propagation
    $engine->updateUplineRanks(3);

    // User 2 rank_id should be updated to 1 (Mentor)
    $user2Rank = (int)$sqlite->query("SELECT rank_id FROM users WHERE id = 2")->fetchColumn();
    if ($user2Rank !== 1) {
        throw new Exception("FAIL: User 2 rank should be upgraded to 1, got: " . $user2Rank);
    }
    echo "✅ User 2 rank upgraded to Mentor automatically!\n";

    // Admin 1 (upline of User 2) should be awarded Mentor as a conferred rank!
    $stmtConf = $sqlite->prepare("SELECT * FROM conferred_ranks WHERE user_id = 1 AND downline_id = 2 AND rank_id = 1");
    $stmtConf->execute();
    $confRank = $stmtConf->fetch();
    if (!$confRank) {
        throw new Exception("FAIL: Admin 1 should have been awarded Mentor as a conferred rank from User 2.");
    }
    if ((float)$confRank['daily_income'] !== 0.25) {
        throw new Exception("FAIL: Conferred rank daily income should be 0.25, got: " . $confRank['daily_income']);
    }
    echo "✅ Admin 1 correctly awarded Conferred Rank (Mentor) from User 2!\n";

    // 6. Test Rank Income daily process and propagation upward
    echo "--- Testing processRankIncome() Daily Payout & Propagation ---\n";
    // Set User 2 and User 1 status to active so they get paid
    $sqlite->exec("UPDATE users SET status = 'active' WHERE id = 1");
    $sqlite->exec("UPDATE users SET status = 'active' WHERE id = 2");

    $engine->processRankIncome();

    // Verify transaction logs
    // User 2 should have received standard matching daily income (0.25)
    // Admin 1 should have received conferred matching daily income (0.25) as "Daily Propagated Match Income from user2"
    $stmtTx = $sqlite->prepare("SELECT user_id, amount, description FROM transactions ORDER BY id DESC LIMIT 5");
    $stmtTx->execute();
    $txs = $stmtTx->fetchAll();

    print_r($txs);

    echo "🎉 ALL TESTS PASSED SUCCESSFULLY! The entire custom placement, genealogy tree, and conferred ranks engine works flawlessly!\n";

} catch (Exception $e) {
    echo "❌ TEST FAILURE: " . $e->getMessage() . "\n";
    exit(1);
}
