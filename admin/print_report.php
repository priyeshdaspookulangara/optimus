<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

if (!isset($_SESSION['admin_id'])) {
    die("Unauthorized access.");
}

$db = Database::getInstance()->getConnection();
$type = $_GET['type'] ?? 'payouts';

// Determine Report Name based on type
switch ($type) {
    case 'payouts':
        $reportName = "Income Payout Summary Report";
        $data = $db->query("SELECT type, SUM(amount) as total FROM transactions WHERE type IN ('ROI', 'LEVEL_INCOME', 'RANK_INCOME') GROUP BY type")->fetchAll();
        break;
    case 'volume':
        $reportName = "Recent Business Volume Report";
        $data = $db->query("SELECT DATE(created_at) as date, SUM(amount) as volume FROM investments GROUP BY DATE(created_at) ORDER BY date DESC LIMIT 30")->fetchAll();
        break;
    case 'investors':
        $reportName = "Top 10 Investors Report";
        $data = $db->query("SELECT username, email, total_investment, created_at FROM users ORDER BY total_investment DESC LIMIT 10")->fetchAll();
        break;
    case 'withdrawals':
        $reportName = "Member Withdrawals Report";
        $data = $db->query("SELECT t.*, u.username FROM transactions t JOIN users u ON t.user_id = u.id WHERE t.type = 'WITHDRAWAL' ORDER BY t.created_at DESC")->fetchAll();
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
    <title><?php echo htmlspecialchars($reportName); ?> - Print</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css">
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
        .no-print-btn {
            margin-bottom: 20px;
        }
        @media print {
            .no-print-btn {
                display: none;
            }
            body {
                padding: 0;
            }
        }
    </style>
</head>
<body>

    <div class="container text-center no-print-btn">
        <button onclick="window.print();" class="btn btn-primary px-4 py-2"><i class="fa fa-print me-2"></i> Print Report</button>
        <button onclick="window.close();" class="btn btn-outline-secondary px-4 py-2 ms-2">Close Window</button>
    </div>

    <!-- Exact Printable Format -->
    <div class="print-header">
        <!-- Big Title on top of the first page -->
        <h1 class="big-title"><?php echo htmlspecialchars($reportName); ?></h1>

        <!-- Top Title: Optimus Infinity -->
        <h3 class="top-title">Optimus Infinity</h3>

        <!-- Current Date -->
        <div class="print-date">Date: <?php echo $currentDate; ?></div>

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
                    <?php foreach($data as $stat): ?>
                    <tr>
                        <td><strong><?php echo $stat['type']; ?></strong></td>
                        <td>$<?php echo number_format($stat['total'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
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
                    <?php foreach($data as $v): ?>
                    <tr>
                        <td><?php echo $v['date']; ?></td>
                        <td>$<?php echo number_format($v['volume'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
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
                    <?php foreach($data as $top): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($top['username']); ?></strong></td>
                        <td><?php echo htmlspecialchars($top['email']); ?></td>
                        <td>$<?php echo number_format($top['total_investment'], 2); ?></td>
                        <td><?php echo date('Y-m-d', strtotime($top['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
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
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <script>
        // Auto trigger print dialog on load for user convenience
        window.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => {
                window.print();
            }, 500);
        });
    </script>
</body>
</html>
