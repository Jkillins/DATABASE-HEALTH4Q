<?php
/**
 * assistant-broadcast.php
 * Notification System - Create and manage clinic-wide broadcasts, alerts, and
 * announcements to communicate with staff and patients in real-time.
 */

require_once 'config.php';
requireRole(ROLE_ASSISTANT);

$pdo = getPDO();
$user_id = getCurrentUserId();
$assistant_id = getCurrentRoleId();

// Get assistant clinic info
$stmt = $pdo->prepare('SELECT clinic FROM clinical_assistant WHERE assistant_id = ?');
$stmt->execute([$assistant_id]);
$assistant = $stmt->fetch();
$clinic = $assistant['clinic'] ?? 'General';

$message = '';

// Handle new broadcast
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_broadcast'])) {
    $title = sanitize($_POST['title'] ?? '');
    $msg_body = sanitize($_POST['message'] ?? '');
    $msg_type = sanitize($_POST['message_type'] ?? 'announcement');
    $priority = sanitize($_POST['priority'] ?? 'normal');
    
    if ($title && $msg_body) {
        try {
            $stmt = $pdo->prepare('
                INSERT INTO broadcast_message (created_by, clinic_name, title, message, message_type, priority, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
            ');
            $stmt->execute([$user_id, $clinic, $title, $msg_body, $msg_type, $priority]);
            $message = '✓ Broadcast sent successfully!';
        } catch (Exception $e) {
            $message = '✗ Error: ' . $e->getMessage();
        }
    }
}

// Get recent broadcasts
$stmt = $pdo->prepare('
    SELECT * FROM broadcast_message
    WHERE clinic_name = ? AND (expires_at IS NULL OR expires_at > NOW())
    ORDER BY created_at DESC
    LIMIT 50
');
$stmt->execute([$clinic]);
$broadcasts = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinic Alerts | Health4Q</title>
    <link rel="icon" type="image/png" href="images/Logo_only.png">
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-green: #1a4d34;
            --accent-green: #2d6a4f;
            --light-bg: #c5e6e1;
            --white: #ffffff;
            --text-dark: #1b4332;
            --text-light: #666;
            --border-color: #d0e8e0;
            --danger: #d90429;
            --warning: #f77f00;
            --info: #4a7c2c;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Quicksand', sans-serif;
            background: radial-gradient(circle at center, #d8f3dc 0%, var(--light-bg) 100%);
            min-height: 100vh;
            color: var(--text-dark);
        }

        .top-nav {
            background: var(--primary-green);
            padding: 12px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .nav-brand img { height: 40px; filter: brightness(0) invert(1); }
        .nav-links { display: flex; gap: 12px; }
        .nav-links a {
            color: white;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            padding: 8px 15px;
            border-radius: 8px;
            background: rgba(255,255,255,0.1);
            transition: 0.3s;
        }
        .nav-links a:hover, .nav-links a.active { background: var(--accent-green); }

        .logout-btn {
            background: var(--danger);
            color: white;
            padding: 8px 16px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            font-size: 12px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        h1 {
            font-size: 28px;
            color: var(--primary-green);
            margin-bottom: 25px;
        }

        .message {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 600;
            border-left: 4px solid;
        }

        .message.success {
            background: #d4edda;
            border-color: var(--info);
            color: #155724;
        }

        .message.error {
            background: #f8d7da;
            border-color: var(--danger);
            color: #721c24;
        }

        .card {
            background: var(--white);
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .card h2 {
            color: var(--primary-green);
            font-size: 18px;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--border-color);
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            color: var(--primary-green);
            margin-bottom: 6px;
            font-size: 13px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 10px;
            border: 2px solid var(--border-color);
            border-radius: 6px;
            font-family: 'Quicksand', sans-serif;
            font-size: 13px;
            transition: 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent-green);
            background: var(--light-bg);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .btn-submit {
            background: var(--accent-green);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 6px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            transition: 0.3s;
        }

        .btn-submit:hover { background: var(--primary-green); }

        .broadcasts-grid {
            display: grid;
            gap: 15px;
        }

        .broadcast-item {
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid var(--border-color);
        }

        .broadcast-item.urgent { border-left-color: var(--danger); background: #fff5f5; }
        .broadcast-item.high { border-left-color: var(--warning); background: #fffaf0; }
        .broadcast-item.normal { border-left-color: var(--info); background: #f0faf7; }

        .broadcast-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 10px;
        }

        .broadcast-title {
            font-weight: 700;
            color: var(--primary-green);
            font-size: 14px;
        }

        .priority-badge {
            background: var(--info);
            color: white;
            padding: 3px 10px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .priority-badge.urgent { background: var(--danger); }
        .priority-badge.high { background: var(--warning); }

        .broadcast-type {
            font-size: 11px;
            color: var(--text-light);
            margin-top: 5px;
        }

        .broadcast-message {
            font-size: 13px;
            color: var(--text-dark);
            line-height: 1.5;
            margin: 10px 0;
        }

        .broadcast-meta {
            font-size: 11px;
            color: var(--text-light);
            margin-top: 8px;
        }

        .empty-state {
            padding: 40px;
            text-align: center;
            color: var(--text-light);
        }

        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
            .broadcast-header { flex-direction: column; gap: 8px; }
        }
    </style>
</head>
<body>
    <div class="top-nav">
        <div class="nav-brand">
            <img src="images/Logo_only.png" alt="Health4Q">
        </div>
        <div class="nav-links">
            <a href="assistant-dashboard.php">Overview</a>
            <a href="assistant-queue.php">📋 Live Queue</a>
            <a href="assistant-broadcast.php" class="active">📢 Alerts</a>
            <a href="assistant-referral.php">📤 Referrals</a>
            <a href="assistant-patient-search.php">🔍 Search</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>

    <div class="container">
        <h1>📢 Clinic Alerts & Announcements</h1>

        <?php if ($message): ?>
            <div class="message <?php echo strpos($message, '✓') !== false ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Create Broadcast -->
        <div class="card">
            <h2>+ Create New Alert</h2>
            <form method="POST">
                <div class="form-row">
                    <div class="form-group">
                        <label>Title *</label>
                        <input type="text" name="title" placeholder="Alert title..." required>
                    </div>
                    <div class="form-group">
                        <label>Type</label>
                        <select name="message_type">
                            <option value="announcement">Announcement</option>
                            <option value="reminder">Reminder</option>
                            <option value="alert">Alert</option>
                            <option value="warning">Warning</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Priority</label>
                        <select name="priority">
                            <option value="low">Low</option>
                            <option value="normal" selected>Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Message *</label>
                    <textarea name="message" placeholder="Enter your message here..." required></textarea>
                </div>

                <button type="submit" name="create_broadcast" class="btn-submit">📢 Send Alert</button>
            </form>
        </div>

        <!-- Active Broadcasts -->
        <div class="card">
            <h2>📋 Active Broadcasts</h2>
            <?php if (count($broadcasts) > 0): ?>
                <div class="broadcasts-grid">
                    <?php foreach ($broadcasts as $b): ?>
                        <div class="broadcast-item <?php echo strtolower($b['priority']); ?>">
                            <div class="broadcast-header">
                                <div class="broadcast-title"><?php echo htmlspecialchars($b['title']); ?></div>
                                <span class="priority-badge <?php echo strtolower($b['priority']); ?>">
                                    <?php echo ucfirst($b['priority']); ?>
                                </span>
                            </div>
                            <div class="broadcast-type">
                                📌 <?php echo ucfirst(str_replace('_', ' ', $b['message_type'])); ?>
                            </div>
                            <div class="broadcast-message">
                                <?php echo htmlspecialchars($b['message']); ?>
                            </div>
                            <div class="broadcast-meta">
                                👁️ <?php echo $b['view_count']; ?> views • <?php echo date('M d, Y H:i', strtotime($b['created_at'])); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <p>No active broadcasts.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
