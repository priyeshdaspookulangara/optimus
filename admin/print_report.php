<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['admin_id'])) {
    die("Unauthorized access.");
}

$db = Database::getInstance()->getConnection();
$type = $_GET['type'] ?? 'payouts';

// Handle Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 15; // 15 rows per page for elegant printing
$offset = ($page - 1) * $limit;

$totalRecords = 0;
$totalPages = 1;
$data = [];

// Determine Report Name and fetch paginated data based on type
switch ($type) {
    case 'payouts':
        $reportName = "Income Payout Summary Report";
        // Aggregate totals don't require heavy pagination, but we support it for standard layout consistency
        $countQuery = $db->query("SELECT COUNT(DISTINCT type) as count FROM transactions WHERE type IN ('ROI', 'LEVEL_INCOME', 'RANK_INCOME')");
        $totalRecords = (int)$countQuery->fetch()['count'];
        $totalPages = max(1, ceil($totalRecords / $limit));

        $stmt = $db->prepare("SELECT type, SUM(amount) as total FROM transactions WHERE type IN ('ROI', 'LEVEL_INCOME', 'RANK_INCOME') GROUP BY type LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll();
        break;

    case 'volume':
        $reportName = "Recent Business Volume Report";
        $countQuery = $db->query("SELECT COUNT(DISTINCT DATE(created_at)) as count FROM investments");
        $totalRecords = (int)$countQuery->fetch()['count'];
        $totalPages = max(1, ceil($totalRecords / $limit));

        $stmt = $db->prepare("SELECT DATE(created_at) as date, SUM(amount) as volume FROM investments GROUP BY DATE(created_at) ORDER BY date DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll();
        break;

    case 'investors':
        $reportName = "Top 10 Investors Report";
        // Restricted to 10 as per specification, so 1 page fits all
        $totalRecords = 10;
        $totalPages = 1;

        $stmt = $db->prepare("SELECT username, email, total_investment, created_at FROM users ORDER BY total_investment DESC LIMIT 10");
        $stmt->execute();
        $data = $stmt->fetchAll();
        break;

    case 'withdrawals':
        $reportName = "Member Withdrawals Report";
        $countQuery = $db->query("SELECT COUNT(*) as count FROM transactions WHERE type = 'WITHDRAWAL'");
        $totalRecords = (int)$countQuery->fetch()['count'];
        $totalPages = max(1, ceil($totalRecords / $limit));

        $stmt = $db->prepare("SELECT t.*, u.username FROM transactions t JOIN users u ON t.user_id = u.id WHERE t.type = 'WITHDRAWAL' ORDER BY t.created_at DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll();
        break;

    default:
        $reportName = "Business Performance Report";
        $data = [];
        break;
}

$currentDate = date('d M, Y h:i a');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($reportName); ?> - Page <?php echo $page; ?> - Optimus Infinity</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #fff;
            color: #000;
            font-family: 'Poppins', sans-serif;
            padding: 30px;
        }
        .print-header {
            text-align: center;
            margin-bottom: 20px;
        }
        .big-title {
            font-size: 2.25rem;
            font-weight: 800;
            text-transform: uppercase;
            margin-bottom: 5px;
            color: #000;
        }
        .top-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #555;
            margin-bottom: 5px;
        }
        .print-date {
            font-size: 1rem;
            color: #777;
            margin-bottom: 15px;
        }
        hr {
            border-top: 2px solid #000 !important;
            opacity: 1;
            margin-bottom: 30px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        table th, table td {
            border: 1px solid #000 !important;
            padding: 12px;
            text-align: left;
            color: #000 !important;
        }
        table th {
            background-color: #f2f2f2 !important;
            font-weight: bold;
        }
        .pagination-container {
            margin-top: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                padding: 0;
            }
        }
    </style>
</head>
<body>

    <!-- Print control bar (hidden during print) -->
    <div class="container text-center no-print mb-4">
        <button onclick="window.print();" class="btn btn-primary px-4 py-2"><i class="fa fa-print me-2"></i> Print This Page</button>
        <button onclick="window.close();" class="btn btn-outline-secondary px-4 py-2 ms-2">Close Window</button>
    </div>

    <!-- Exact Printable Format -->
    <div class="print-header">
        <!-- Big Title on top of the first page -->
        <h1 class="big-title"><?php echo htmlspecialchars($reportName); ?></h1>

        <!-- Top Title: Optimus Infinity -->
        <h3 class="top-title">Optimus Infinity</h3>

        <!-- Current Date -->
        <div class="print-date">Date: <?php echo $currentDate; ?> (Page <?php echo $page; ?> of <?php echo $totalPages; ?>)</div>

        <!-- Horizontal line -->
        <hr>
    </div>

    <!-- Data Table -->
    <div class="table-responsive">
        <?php if ($type === 'payouts'): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Income Type</th>
                        <th>Total Payout (USD)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($data)): ?>
                        <tr><td colspan="2" class="text-center">No data available</td></tr>
                    <?php else: ?>
                        <?php foreach($data as $stat): ?>
                        <tr>
                            <td><strong><?php echo $stat['type']; ?></strong></td>
                            <td>$<?php echo number_format($stat['total'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php elseif ($type === 'volume'): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>New Investment Volume (USD)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($data)): ?>
                        <tr><td colspan="2" class="text-center">No data available</td></tr>
                    <?php else: ?>
                        <?php foreach($data as $v): ?>
                        <tr>
                            <td><?php echo $v['date']; ?></td>
                            <td>$<?php echo number_format($v['volume'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php elseif ($type === 'investors'): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Total Invested (USD)</th>
                        <th>Join Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($data)): ?>
                        <tr><td colspan="4" class="text-center">No data available</td></tr>
                    <?php else: ?>
                        <?php foreach($data as $top): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($top['username']); ?></strong></td>
                            <td><?php echo htmlspecialchars($top['email']); ?></td>
                            <td>$<?php echo number_format($top['total_investment'], 2); ?></td>
                            <td><?php echo date('Y-m-d', strtotime($top['created_at'])); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php elseif ($type === 'withdrawals'): ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Transaction ID</th>
                        <th>Date & Time</th>
                        <th>Username</th>
                        <th>Requested Amount</th>
                        <th>Gas Fee</th>
                        <th>Net Paid Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($data)): ?>
                        <tr><td colspan="6" class="text-center">No data available</td></tr>
                    <?php else: ?>
                        <?php foreach($data as $w): ?>
                        <tr>
                            <td>#<?php echo $w['id']; ?></td>
                            <td><?php echo date('Y-m-d H:i:s', strtotime($w['created_at'])); ?></td>
                            <td><strong><?php echo htmlspecialchars($w['username']); ?></strong></td>
                            <td>$<?php echo number_format($w['amount'], 2); ?></td>
                            <td>$<?php echo number_format($w['fee'], 2); ?></td>
                            <td>$<?php echo number_format($w['amount'] - $w['fee'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Pagination Navigation (Hidden during actual paper print) -->
    <div class="pagination-container no-print">
        <div>
            <span class="text-muted">Showing <?php echo count($data); ?> of <?php echo $totalRecords; ?> total records</span>
        </div>
        <nav>
            <ul class="pagination mb-0">
                <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?type=<?php echo urlencode($type); ?>&page=<?php echo $page - 1; ?>"><i class="fa fa-chevron-left me-1"></i> Previous</a>
                </li>
                <li class="page-item disabled">
                    <span class="page-link">Page <?php echo $page; ?> of <?php echo $totalPages; ?></span>
                </li>
                <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?type=<?php echo urlencode($type); ?>&page=<?php echo $page + 1; ?>">Next <i class="fa fa-chevron-right ms-1"></i></a>
                </li>
            </ul>
        </nav>
    </div>

</body>
</html>
