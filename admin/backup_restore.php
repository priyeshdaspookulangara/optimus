<?php
session_start();

// Strict admin session verification at the absolute top of the file
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/../includes/db.php';
$db = Database::getInstance()->getConnection();

$message = '';
$messageType = '';

/**
 * Robust SQL script splitter.
 * Safely splits a multi-statement SQL string by semicolons,
 * respecting single quotes, double quotes, and escaped characters.
 */
function splitSqlQueries($sql) {
    $queries = [];
    $query = '';
    $inSingleQuote = false;
    $inDoubleQuote = false;
    $escaped = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];

        // Handle escape character
        if ($escaped) {
            $query .= $char;
            $escaped = false;
            continue;
        }

        if ($char === '\\') {
            $query .= $char;
            $escaped = true;
            continue;
        }

        // Handle single quote boundaries
        if ($char === "'" && !$inDoubleQuote) {
            $inSingleQuote = !$inSingleQuote;
        }
        // Handle double quote boundaries
        elseif ($char === '"' && !$inSingleQuote) {
            $inDoubleQuote = !$inDoubleQuote;
        }

        // If semicolon is found outside quotes, it marks the end of a statement
        if ($char === ';' && !$inSingleQuote && !$inDoubleQuote) {
            $queries[] = trim($query);
            $query = '';
        } else {
            $query .= $char;
        }
    }

    $lastQuery = trim($query);
    if (!empty($lastQuery)) {
        $queries[] = $lastQuery;
    }

    return $queries;
}

// Handle Backup Export Request
if (isset($_GET['action']) && $_GET['action'] == 'backup') {
    try {
        $tables = [];
        $result = $db->query("SHOW TABLES");
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }

        $sqlDump = "-- MLM Application Database Backup\n";
        $sqlDump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $sqlDump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $table) {
            // Get Create Table statement
            $stmt = $db->prepare("SHOW CREATE TABLE `" . $table . "`");
            $stmt->execute();
            $createTableRow = $stmt->fetch(PDO::FETCH_NUM);
            $sqlDump .= "DROP TABLE IF EXISTS `" . $table . "`;\n";
            $sqlDump .= $createTableRow[1] . ";\n\n";

            // Get Table Data
            $stmt = $db->prepare("SELECT * FROM `" . $table . "`");
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($rows) > 0) {
                $sqlDump .= "INSERT INTO `" . $table . "` VALUES\n";
                $inserts = [];
                foreach ($rows as $row) {
                    $values = [];
                    foreach ($row as $key => $value) {
                        if ($value === null) {
                            $values[] = "NULL";
                        } else {
                            $values[] = $db->quote($value);
                        }
                    }
                    $inserts[] = "(" . implode(", ", $values) . ")";
                }
                $sqlDump .= implode(",\n", $inserts) . ";\n\n";
            }
        }

        $sqlDump .= "SET FOREIGN_KEY_CHECKS=1;\n";

        // Download headers
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="mlm_backup_' . date('Ymd_His') . '.sql"');
        header('Content-Length: ' . strlen($sqlDump));
        echo $sqlDump;
        exit();
    } catch (Exception $e) {
        $message = "Backup failed: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Handle Backup Restore Request
if (isset($_POST['action']) && $_POST['action'] == 'restore') {
    if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] == UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['backup_file']['tmp_name'];
        $fileName = $_FILES['backup_file']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($fileExtension !== 'sql') {
            $message = "Invalid file type. Only .sql files are allowed.";
            $messageType = "danger";
        } else {
            try {
                $sqlContent = file_get_contents($fileTmpPath);

                // Disable foreign key checks during restore
                $db->exec("SET FOREIGN_KEY_CHECKS=0;");

                // Execute SQL statements using our robust parser
                $queries = splitSqlQueries($sqlContent);

                $db->beginTransaction();
                foreach ($queries as $query) {
                    $query = trim($query);
                    if (!empty($query)) {
                        $db->exec($query);
                    }
                }
                $db->commit();

                // Re-enable foreign key checks
                $db->exec("SET FOREIGN_KEY_CHECKS=1;");

                $message = "Database successfully restored from " . htmlspecialchars($fileName);
                $messageType = "success";
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $db->exec("SET FOREIGN_KEY_CHECKS=1;");
                $message = "Restore failed: " . $e->getMessage();
                $messageType = "danger";
            }
        }
    } else {
        $message = "Please select a valid backup SQL file to upload.";
        $messageType = "danger";
    }
}

$pageTitle = 'Database Backup & Restore';
include __DIR__ . '/includes/header.php';
?>

<div class="mb-4">
    <h3>Database Backup & Restore</h3>
    <p class="text-muted">Manage your MLM system data safely. Export database backups or restore past system states.</p>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
        <?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Backup Export Column -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header bg-primary text-white">
                <h5 class="card-title mb-0">Export Database Backup</h5>
            </div>
            <div class="card-body d-flex flex-column justify-content-between">
                <div>
                    <p class="card-text">
                        Generate a secure `.sql` dump of all database tables, containing structure and contents (such as members, investments, transaction logs, PINs, and wallets).
                    </p>
                    <div class="alert alert-warning">
                        <strong>Recommendation:</strong> Always generate a backup before making major system changes, updating packages, or performing server migrations.
                    </div>
                </div>
                <div class="text-center mt-3">
                    <a href="backup_restore.php?action=backup" class="btn btn-primary btn-lg w-100">
                        <i class="bi bi-download"></i> Download SQL Backup
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Backup Restore Column -->
    <div class="col-md-6 mb-4">
        <div class="card h-100">
            <div class="card-header bg-danger text-white">
                <h5 class="card-title mb-0">Restore Database Backup</h5>
            </div>
            <div class="card-body">
                <p class="card-text">
                    Restore the MLM database to a previous state by uploading a `.sql` backup file.
                </p>
                <div class="alert alert-danger">
                    <strong>Warning:</strong> Restoring will completely overwrite existing tables, active user sessions, wallet balances, and current MLM genealogy. Use with extreme care.
                </div>
                <form method="post" enctype="multipart/form-data" class="mt-4">
                    <input type="hidden" name="action" value="restore">
                    <div class="mb-3">
                        <label for="backup_file" class="form-label font-bold">Select SQL Backup File:</label>
                        <input class="form-control" type="file" id="backup_file" name="backup_file" accept=".sql" required>
                    </div>
                    <button type="submit" class="btn btn-danger w-100" onclick="return confirm('ARE YOU ABSOLUTELY SURE? This will permanently overwrite current system data! This action cannot be undone.');">
                        <i class="bi bi-upload"></i> Upload & Restore Backup
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
