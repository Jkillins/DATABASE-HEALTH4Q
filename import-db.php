<?php
header('Content-Type: text/html; charset=utf-8');

$host = '127.0.0.1';
$user = 'root';
$pass = '';

$result = null;
$error = null;
$warning = null;
$mysql_status = null;

// Check if MySQL is running
function checkMySQLStatus() {
    $sock = @fsockopen('127.0.0.1', 3306, $errno, $errstr, 2);
    if ($sock) {
        fclose($sock);
        return true;
    }
    return false;
}

// Try to start MySQL via command line (Windows)
function startMySQL() {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        // Try to start MySQL using net start command
        exec('net start MySQL80 2>&1', $output, $return_code);
        sleep(3); // Wait for MySQL to start
        return checkMySQLStatus();
    }
    return false;
}

$mysql_status = checkMySQLStatus();

if ($_POST['action'] ?? null === 'import') {
    try {
        // Check if MySQL is running, if not try to start it
        if (!$mysql_status) {
            if (startMySQL()) {
                $mysql_status = true;
                $warning = "MySQL was not running. Attempted to start it. Trying import now...";
            } else {
                throw new Exception("MySQL is not running. Please start it in XAMPP Control Panel and try again.");
            }
        }
        
        // Connect to MySQL
        try {
            $pdo = new PDO("mysql:host=$host;port=3306", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (Exception $e) {
            throw new Exception("Cannot connect to MySQL: " . $e->getMessage());
        }
        
        // Read schema file
        $schema = file_get_contents(__DIR__ . '/db/schema.sql');
        
        if (!$schema) {
            throw new Exception("Failed to read schema.sql file");
        }
        
        // Split by statement and execute
        $statements = array_filter(
            array_map('trim', preg_split('/;(?=\s*$|$)/m', $schema)),
            function($stmt) { return !empty($stmt) && !preg_match('/^--/', $stmt); }
        );
        
        $count = 0;
        foreach ($statements as $statement) {
            if (trim($statement)) {
                $pdo->exec($statement);
                $count++;
            }
        }
        
        $result = "✓ Database imported successfully! Executed $count SQL statements.";
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Health4Q - Database Setup</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            padding: 40px;
            max-width: 600px;
            width: 100%;
        }
        h1 { color: #333; margin-bottom: 10px; font-size: 28px; }
        .subtitle { color: #666; margin-bottom: 30px; font-size: 14px; }
        .status-box {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }
        .status-online { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .status-offline { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .status-icon { font-size: 20px; }
        .success { background: #d4edda; color: #155724; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #28a745; }
        .error { background: #f8d7da; color: #721c24; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #dc3545; }
        .warning { background: #fff3cd; color: #856404; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #ffc107; }
        .info { background: #d1ecf1; color: #0c5460; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #17a2b8; }
        .steps {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 25px;
        }
        .steps h3 { color: #333; margin-bottom: 15px; font-size: 16px; }
        .steps ol { margin-left: 20px; }
        .steps li { margin-bottom: 10px; color: #555; line-height: 1.6; }
        .steps li strong { color: #333; }
        .button-group { display: flex; gap: 10px; }
        button {
            flex: 1;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-primary {
            background: #007bff;
            color: white;
        }
        .btn-primary:hover { background: #0056b3; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0, 123, 255, 0.3); }
        .btn-primary:disabled { background: #ccc; cursor: not-allowed; transform: none; }
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        .btn-secondary:hover { background: #5a6268; }
        .code-block {
            background: #f5f5f5;
            padding: 12px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            overflow-x: auto;
            margin: 10px 0;
            border: 1px solid #ddd;
        }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 13px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🗄️ Health4Q Database Setup</h1>
        <p class="subtitle">Import database schema and create tables</p>
        
        <!-- Status Indicator -->
        <div class="status-box <?= $mysql_status ? 'status-online' : 'status-offline' ?>">
            <span class="status-icon"><?= $mysql_status ? '✓' : '✕' ?></span>
            <span>MySQL Status: <?= $mysql_status ? 'RUNNING' : 'NOT RUNNING' ?></span>
        </div>
        
        <!-- Results Messages -->
        <?php if ($result): ?>
            <div class="success">✓ <?= htmlspecialchars($result) ?></div>
        <?php endif; ?>
        
        <?php if ($warning): ?>
            <div class="warning">⚠ <?= htmlspecialchars($warning) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error">✕ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <!-- Instructions -->
        <div class="steps">
            <h3>📋 How to Run Database Setup:</h3>
            <ol>
                <li><strong>Open XAMPP Control Panel</strong><br>
                    <span style="color: #666; font-size: 13px;">Look for "C:\xampp\xampp-control.exe" on your desktop or Start menu</span></li>
                <li><strong>Start MySQL</strong><br>
                    <span style="color: #666; font-size: 13px;">Click the "Start" button next to MySQL module (if not already running)</span></li>
                <li><strong>Start Apache</strong><br>
                    <span style="color: #666; font-size: 13px;">Click the "Start" button next to Apache module</span></li>
                <li><strong>Import Database</strong><br>
                    <span style="color: #666; font-size: 13px;">Click the "Import Database" button below</span></li>
            </ol>
        </div>
        
        <!-- Alternative: Manual Command Line -->
        <div class="info">
            <strong>🖥️ Alternative Method (PowerShell - Windows):</strong><br><br>
            You can also import the database manually using PowerShell:<br><br>
            <div class="code-block">cd "C:\xampp\mysql\bin"
Get-Content "C:\xampp\htdocs\DATABASE-RAMA\Health4q\Health4Q\db\schema.sql" | .\mysql -u root</div>
        </div>
        
        <!-- Import Form -->
        <form method="POST">
            <input type="hidden" name="action" value="import">
            <div class="button-group">
                <button type="submit" class="btn-primary" <?= !$mysql_status ? 'disabled' : '' ?>>
                    📥 Import Database Schema
                </button>
                <button type="button" class="btn-secondary" onclick="location.reload()">
                    🔄 Refresh Status
                </button>
            </div>
        </form>
        
        <!-- Troubleshooting -->
        <div class="footer">
            <strong>⚙️ Troubleshooting:</strong><br>
            • If MySQL still won't start, check XAMPP logs: XAMPP Control Panel → MySQL → Logs<br>
            • Make sure no other MySQL instance is running on port 3306<br>
            • Try running XAMPP Control Panel as Administrator<br>
            • If you get "Access Denied", ensure MySQL has started properly<br>
            <br>
            <strong>📍 Database Location:</strong> <span style="font-family: monospace;">C:\xampp\htdocs\DATABASE-RAMA\Health4q\Health4Q</span>
        </div>
    </div>
</body>
</html>
