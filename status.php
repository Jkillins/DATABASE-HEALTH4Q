<?php
/**
 * Health4Q - System Status & Testing Page
 * Check database connection, sessions, and system status
 */

require_once 'config.php';
require_once 'db.php';

$status = [
    'session' => 'OK',
    'database' => 'ERROR',
    'tables' => [],
    'sample_users' => 0
];

// Check database connection
try {
    $pdo = getPDO();
    $status['database'] = 'OK';
    
    // Check tables
    $tables = ['users', 'patients', 'doctors', 'appointments', 'medical_data'];
    foreach ($tables as $table) {
        $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        $status['tables'][$table] = $stmt->rowCount() > 0 ? 'EXISTS' : 'MISSING';
    }
    
    // Count sample data
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $result = $stmt->fetch();
    $status['sample_users'] = $result['count'];
    
} catch (Exception $e) {
    $status['database'] = 'ERROR: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health4Q - System Status</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #007bff;
            padding-bottom: 10px;
        }
        .status-item {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }
        .status-label {
            font-weight: bold;
            color: #555;
        }
        .status-value {
            padding: 4px 8px;
            border-radius: 4px;
            font-family: monospace;
        }
        .status-ok {
            background: #d4edda;
            color: #155724;
        }
        .status-error {
            background: #f8d7da;
            color: #721c24;
        }
        .links {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #ddd;
        }
        .links a {
            display: inline-block;
            margin: 10px 10px 10px 0;
            padding: 10px 16px;
            background: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }
        .links a:hover {
            background: #0056b3;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        table th {
            background: #f0f0f0;
            padding: 10px;
            text-align: left;
            border-bottom: 2px solid #ddd;
        }
        table td {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .alert {
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Health4Q - System Status</h1>

        <?php if ($status['database'] !== 'OK'): ?>
            <div class="alert">
                ⚠️ Database connection failed. Please ensure MySQL is running and schema is imported.
            </div>
        <?php endif; ?>

        <h2>Status Check</h2>
        
        <div class="status-item">
            <span class="status-label">Session Support</span>
            <span class="status-value status-ok"><?php echo $status['session']; ?></span>
        </div>

        <div class="status-item">
            <span class="status-label">Database Connection</span>
            <span class="status-value <?php echo strpos($status['database'], 'OK') !== false ? 'status-ok' : 'status-error'; ?>">
                <?php echo $status['database']; ?>
            </span>
        </div>

        <div class="status-item">
            <span class="status-label">Sample Users Loaded</span>
            <span class="status-value status-ok"><?php echo $status['sample_users']; ?> users</span>
        </div>

        <h2>Database Tables</h2>
        <table>
            <thead>
                <tr>
                    <th>Table Name</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($status['tables'] as $table => $table_status): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($table); ?></td>
                        <td>
                            <span class="status-value <?php echo $table_status === 'EXISTS' ? 'status-ok' : 'status-error'; ?>">
                                <?php echo $table_status; ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Quick Reference - Test Accounts</h2>
        <table>
            <thead>
                <tr>
                    <th>Role</th>
                    <th>Email</th>
                    <th>Name</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Doctor</td>
                    <td><code>dr.gabriel@example.com</code></td>
                    <td>Dr. Gabriel Silva</td>
                </tr>
                <tr>
                    <td>Patient</td>
                    <td><code>gabriel@example.com</code></td>
                    <td>Gabriel Fernandez</td>
                </tr>
                <tr>
                    <td>Doctor</td>
                    <td><code>ana.cruz@example.com</code></td>
                    <td>Dr. Ana Cruz</td>
                </tr>
            </tbody>
        </table>

        <div class="links">
            <h2>Quick Navigation</h2>
            <a href="index.php">🏠 Home</a>
            <a href="login.php">🔐 Login</a>
            <a href="register.php">📝 Register</a>
            <a href="http://localhost/phpmyadmin" target="_blank">🗄️ phpMyAdmin</a>
        </div>

        <div class="links">
            <h2>Documentation</h2>
            <a href="README-PHP.md">📖 Full Documentation</a>
            <a href="SETUP-GUIDE.md">⚙️ Setup Guide</a>
        </div>
    </div>
</body>
</html>
