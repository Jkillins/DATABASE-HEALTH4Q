<?php
/**
 * patient-notifications.php - Premium Clinical Notification Data Center
 */
require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Access Control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
$user_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'mark_single_read') {
        $notif_id = (int)$_POST['notif_id'];
        
        // Broadcast tracking
        $stmtFetch = $pdo->prepare("SELECT subject, status FROM notification WHERE user_id = ? AND notif_id = ?");
        $stmtFetch->execute([$user_id, $notif_id]);
        $notif = $stmtFetch->fetch(PDO::FETCH_ASSOC);

        if ($notif && $notif['status'] === 'sent') {
            $subject = $notif['subject'];
            if (strpos($subject, "📢 Clinic Alert: ") === 0) {
                $title = str_replace("📢 Clinic Alert: ", "", $subject);
                $stmtView = $pdo->prepare("UPDATE broadcast_message SET view_count = view_count + 1 WHERE title = ?");
                $stmtView->execute([$title]);
            }
        }

        $stmt = $pdo->prepare("UPDATE notification SET status = 'read', read_at = NOW() WHERE user_id = ? AND notif_id = ?");
        if ($stmt->execute([$user_id, $notif_id])) {
            $success_msg = "Notification marked as read.";
        }
    }

    if ($action === 'mark_all_read') {
        $stmtUnread = $pdo->prepare("SELECT subject FROM notification WHERE user_id = ? AND status = 'sent'");
        $stmtUnread->execute([$user_id]);
        $unreads = $stmtUnread->fetchAll(PDO::FETCH_ASSOC);
        foreach ($unreads as $u) {
            $subject = $u['subject'];
            if (strpos($subject, "📢 Clinic Alert: ") === 0) {
                $title = str_replace("📢 Clinic Alert: ", "", $subject);
                $stmtView = $pdo->prepare("UPDATE broadcast_message SET view_count = view_count + 1 WHERE title = ?");
                $stmtView->execute([$title]);
            }
        }

        $stmt = $pdo->prepare("UPDATE notification SET status = 'read', read_at = NOW() WHERE user_id = ? AND status = 'sent'");
        if ($stmt->execute([$user_id])) {
            $success_msg = "All notifications marked as read.";
        }
    }

    if ($action === 'delete_notif') {
        $notif_id = (int)$_POST['notif_id'];
        $stmt = $pdo->prepare("DELETE FROM notification WHERE user_id = ? AND notif_id = ?");
        if ($stmt->execute([$user_id, $notif_id])) {
            $success_msg = "Notification deleted successfully.";
        }
    }
}

// Fetch all notifications for patient
try {
    $stmt = $pdo->prepare("SELECT * FROM notification WHERE user_id = ? ORDER BY sent_at DESC");
    $stmt->execute([$user_id]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_msg = "Failed to load notifications: " . $e->getMessage();
    $notifications = [];
}

// Stats
$total_count = count($notifications);
$unread_count = count(array_filter($notifications, fn($n) => $n['status'] === 'sent'));
$read_count = $total_count - $unread_count;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification Center | Health4Q</title>
    <link rel="icon" type="image/png" href="images/Logo_only.png">
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-green: #1a4d34; 
            --light-bg: #c5e6e1;    
            --white: #ffffff;
            --accent-green: #2d6a4f;
            --text-dark: #1b4332;
            --border-color: #e2e8f0;
            --danger: #d90429;
            --warning: #f59e0b;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Quicksand', sans-serif; }

        body {
            background: radial-gradient(circle at center, #d8f3dc 0%, var(--light-bg) 100%);
            min-height: 100vh;
            color: var(--text-dark);
            display: flex;
            flex-direction: column;
        }

        /* --- NAVIGATION --- */
        .top-nav {
            background: var(--primary-green);
            padding: 12px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .nav-brand img { height: 40px; filter: brightness(0) invert(1); }

        .nav-links { display: flex; gap: 15px; }
        .nav-links a {
            color: white;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            padding: 8px 15px;
            border-radius: 8px;
            background: rgba(255,255,255,0.1);
            transition: 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .nav-links a:hover, .nav-links a.active { background: var(--accent-green); }

        .logout-btn {
            background: var(--danger);
            color: white;
            padding: 8px 18px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            font-size: 12px;
            transition: 0.3s;
        }
        .logout-btn:hover { background: #b00220; }

        /* --- CONTAINER --- */
        .container { max-width: 1100px; margin: 40px auto; padding: 0 20px; flex: 1; }

        /* --- HEADER --- */
        .header-section {
            background: var(--white);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            margin-bottom: 30px;
            border-left: 6px solid var(--primary-green);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        .header-info h1 {
            color: var(--primary-green);
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .header-info p {
            color: var(--text-dark);
            opacity: 0.8;
            font-size: 14px;
            font-weight: 500;
        }

        .header-actions {
            display: flex;
            gap: 10px;
        }

        /* --- STATS GRID --- */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--white);
            padding: 20px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            border-left: 5px solid var(--accent-green);
            display: flex;
            flex-direction: column;
        }

        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.info { border-left-color: var(--primary-green); }

        .stat-card .val {
            font-size: 26px;
            font-weight: 800;
            color: var(--primary-green);
        }

        .stat-card .label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #64748b;
            font-weight: 700;
            margin-top: 5px;
        }

        /* --- SEARCH AND FILTERS --- */
        .controls-card {
            background: var(--white);
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .search-bar {
            flex: 1;
            position: relative;
        }

        .search-bar input {
            width: 100%;
            padding: 12px 20px 12px 45px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            outline: none;
            font-size: 0.95rem;
            font-weight: 600;
            transition: 0.3s;
        }
        .search-bar input:focus {
            border-color: var(--accent-green);
        }

        .search-bar i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
            font-size: 1.1rem;
        }

        .filter-status {
            padding: 12px 20px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            outline: none;
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text-dark);
            background: white;
            cursor: pointer;
            transition: 0.3s;
        }
        .filter-status:focus {
            border-color: var(--accent-green);
        }

        /* --- NOTIFICATION ITEMS --- */
        .notif-list-container {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-bottom: 40px;
        }

        .notif-item {
            background: var(--white);
            border-radius: 16px;
            padding: 20px 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            border: 1.5px solid var(--border-color);
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 20px;
            transition: all 0.2s ease;
        }

        .notif-item:hover {
            transform: translateY(-2px);
            border-color: var(--accent-green);
            box-shadow: 0 8px 20px rgba(45, 106, 79, 0.08);
        }

        .notif-item.unread {
            background: #f0fdf4;
            border-left: 5px solid var(--accent-green);
        }

        .notif-icon-circle {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #e6f7f4;
            color: var(--accent-green);
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 1.4rem;
        }

        .notif-item.unread .notif-icon-circle {
            background: #d8f3dc;
        }

        .notif-body-col h3 {
            font-size: 1.05rem;
            font-weight: 800;
            color: var(--primary-green);
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .notif-body-col h3 .unread-dot {
            width: 8px;
            height: 8px;
            background: var(--danger);
            border-radius: 50%;
            display: inline-block;
        }

        .notif-text {
            font-size: 0.9rem;
            color: #4b5563;
            line-height: 1.5;
            font-weight: 500;
        }

        .notif-date {
            font-size: 0.72rem;
            color: #9ca3af;
            font-weight: 600;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .notif-actions-col {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        /* --- BUTTONS --- */
        .btn-pill {
            padding: 8px 18px;
            border-radius: 10px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-green-pill { background: #e6f9f0; color: #15803d; border: 1.5px solid #cbd5e1; }
        .btn-green-pill:hover { background: var(--accent-green); color: white; border-color: var(--accent-green); }

        .btn-action-view { background: #e0f2fe; color: #0369a1; border: 1.5px solid #bae6fd; }
        .btn-action-view:hover { background: #0369a1; color: white; }

        .btn-red-pill { background: #ffebeb; color: var(--danger); border: 1.5px solid #fecdd3; }
        .btn-red-pill:hover { background: var(--danger); color: white; }

        .btn-mark-all {
            background: var(--primary-green);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.3s;
            font-size: 13px;
        }
        .btn-mark-all:hover {
            background: var(--accent-green);
            box-shadow: 0 5px 15px rgba(26, 77, 46, 0.2);
        }

        /* --- ALERTS --- */
        .alert {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 30px;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: #e6f9f0; color: #0f5132; border: 1px solid #c7f3de; }
        .alert-error { background: #ffebeb; color: var(--danger); border: 1px solid #fecdd3; }

        .empty-state {
            text-align: center;
            background: var(--white);
            border-radius: 20px;
            padding: 60px 20px;
            border: 2px dashed var(--border-color);
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
        }
    </style>
</head>
<body>

    <!-- Unified Top Nav -->
    <nav class="top-nav">
        <div class="nav-brand">
            <img src="images/Logo_only.png" alt="Health4Q">
        </div>
        <div class="nav-links">
            <a href="patient-dashboard.php">🏠 Dashboard</a>
            <a href="patientprofile.php">👤 Profile</a>
            <a href="patientappoint.php">📅 Appointments</a>
            <a href="patient-prescriptions.php">💊 Prescriptions</a>
            <a href="patient-lab-results.php">🧪 Lab Results</a>
            <a href="patientmedhist.php">📜 History</a>
            <a href="patientreqmed.php">🔍 Request Records</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </nav>

    <div class="container">
        
        <!-- Header -->
        <div class="header-section">
            <div class="header-info">
                <h1>🔔 Notification Data Center</h1>
                <p>Track live clinic announcements, check-ins, queue tickets, and appointment summaries.</p>
            </div>
            <div class="header-actions">
                <?php if ($unread_count > 0): ?>
                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="action" value="mark_all_read">
                        <button type="submit" class="btn-mark-all">
                            <i class="fa-solid fa-square-check"></i> Mark All as Read
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($success_msg)): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success_msg); ?>
            </div>
        <?php endif; ?>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card info">
                <span class="val"><?php echo $total_count; ?></span>
                <span class="label">Total Notifications</span>
            </div>
            <div class="stat-card warning">
                <span class="val"><?php echo $unread_count; ?></span>
                <span class="label">Unread Updates</span>
            </div>
            <div class="stat-card">
                <span class="val"><?php echo $read_count; ?></span>
                <span class="label">Archived Logs</span>
            </div>
        </div>

        <!-- Controls -->
        <div class="controls-card">
            <div class="search-bar">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="notifSearch" placeholder="Search by subject or content text..." onkeyup="filterNotifications()">
            </div>
            
            <select id="notifFilter" class="filter-status" onchange="filterNotifications()">
                <option value="all">Show All Logs</option>
                <option value="unread">Unread Only</option>
                <option value="read">Read Only</option>
            </select>
        </div>

        <!-- Notification List -->
        <div class="notif-list-container" id="notifListContainer">
            <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    <i class="fa-regular fa-bell-slash" style="font-size: 3rem; color: #cbd5e1; display: block; margin-bottom: 15px;"></i>
                    <strong style="font-size: 1.1rem; color: var(--primary-green); display: block;">No notifications found</strong>
                    <p style="color: #64748b; font-size: 0.85rem; margin-top: 5px; font-weight: 500;">You currently have a clean inbox with no pending notifications.</p>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $n): 
                    $isUnread = $n['status'] === 'sent';
                    
                    // Determine redirect URL based on subject/body content
                    $redirect_url = '';
                    $lowerSubject = strtolower($n['subject']);
                    $lowerBody = strtolower($n['body']);
                    
                    if (strpos($lowerSubject, 'booking') !== false || strpos($lowerSubject, 'appointment') !== false || strpos($lowerBody, 'appointment') !== false) {
                        $redirect_url = 'patientappoint.php';
                    } elseif (strpos($lowerSubject, 'prescription') !== false || strpos($lowerSubject, 'medicine') !== false || strpos($lowerBody, 'prescription') !== false) {
                        $redirect_url = 'patient-prescriptions.php';
                    } elseif (strpos($lowerSubject, 'lab') !== false || strpos($lowerSubject, 'test') !== false || strpos($lowerBody, 'laboratory') !== false) {
                        $redirect_url = 'patient-lab-results.php';
                    } elseif (strpos($lowerSubject, 'record') !== false || strpos($lowerSubject, 'referral') !== false || strpos($lowerBody, 'clinical') !== false) {
                        $redirect_url = 'patientmedhist.php';
                    } else {
                        $redirect_url = 'patient-dashboard.php';
                    }
                ?>
                    <div class="notif-item <?php echo $isUnread ? 'unread' : ''; ?>" 
                         data-status="<?php echo $isUnread ? 'unread' : 'read'; ?>"
                         data-text="<?php echo htmlspecialchars(strtolower($n['subject'] . ' ' . $n['body'])); ?>">
                        
                        <div class="notif-icon-circle">
                            <?php 
                            if (strpos($lowerSubject, 'booking') !== false || strpos($lowerSubject, 'appointment') !== false) {
                                echo '📅';
                            } elseif (strpos($lowerSubject, 'prescription') !== false || strpos($lowerSubject, 'medicine') !== false) {
                                echo '💊';
                            } elseif (strpos($lowerSubject, 'lab') !== false || strpos($lowerSubject, 'test') !== false) {
                                echo '🧪';
                            } elseif (strpos($lowerSubject, 'queue') !== false || strpos($lowerSubject, 'ticket') !== false) {
                                echo '🎫';
                            } else {
                                echo '🔔';
                            }
                            ?>
                        </div>

                        <div class="notif-body-col">
                            <h3>
                                <?php if ($isUnread): ?><span class="unread-dot"></span><?php endif; ?>
                                <?php echo htmlspecialchars($n['subject']); ?>
                            </h3>
                            <p class="notif-text"><?php echo htmlspecialchars($n['body']); ?></p>
                            <div class="notif-date">
                                <i class="fa-regular fa-clock"></i> Received: <?php echo date('F d, Y | h:i A', strtotime($n['sent_at'])); ?>
                            </div>
                        </div>

                        <div class="notif-actions-col">
                            <?php if ($redirect_url): ?>
                                <a href="<?php echo $redirect_url; ?>" class="btn-pill btn-action-view" onclick="markReadAndRedirect(<?php echo $n['notif_id']; ?>, event, '<?php echo $redirect_url; ?>')">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Go to Data
                                </a>
                            <?php endif; ?>
                            
                            <?php if ($isUnread): ?>
                                <form method="POST" style="margin: 0; display: inline;">
                                    <input type="hidden" name="action" value="mark_single_read">
                                    <input type="hidden" name="notif_id" value="<?php echo $n['notif_id']; ?>">
                                    <button type="submit" class="btn-pill btn-green-pill">
                                        <i class="fa-solid fa-check"></i> Read
                                    </button>
                                </form>
                            <?php endif; ?>

                            <form method="POST" style="margin: 0; display: inline;">
                                <input type="hidden" name="action" value="delete_notif">
                                <input type="hidden" name="notif_id" value="<?php echo $n['notif_id']; ?>">
                                <button type="submit" class="btn-pill btn-red-pill">
                                    <i class="fa-solid fa-trash-can"></i> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div id="noResults" style="display: none;" class="empty-state">
            <i class="fa-solid fa-magnifying-glass" style="font-size: 3rem; color: #cbd5e1; display: block; margin-bottom: 15px;"></i>
            <strong style="font-size: 1.1rem; color: var(--primary-green); display: block;">No matching results found</strong>
            <p style="color: #64748b; font-size: 0.85rem; margin-top: 5px; font-weight: 500;">Adjust your search keyword or filters and try again.</p>
        </div>

    </div>

    <script>
        function filterNotifications() {
            const searchQuery = document.getElementById('notifSearch').value.toLowerCase().trim();
            const filterValue = document.getElementById('notifFilter').value;
            const items = document.querySelectorAll('.notif-item');
            const noResults = document.getElementById('noResults');
            let visibleCount = 0;

            items.forEach(item => {
                const status = item.getAttribute('data-status');
                const text = item.getAttribute('data-text');

                const matchesSearch = text.includes(searchQuery);
                const matchesFilter = (filterValue === 'all') || (status === filterValue);

                if (matchesSearch && matchesFilter) {
                    item.style.display = 'grid';
                    visibleCount++;
                } else {
                    item.style.display = 'none';
                }
            });

            if (visibleCount === 0 && items.length > 0) {
                noResults.style.display = 'block';
            } else {
                noResults.style.display = 'none';
            }
        }

        function markReadAndRedirect(notifId, event, url) {
            event.preventDefault();
            
            // Perform fetch to mark notification as read in background before redirecting
            const formData = new FormData();
            formData.append('action', 'mark_read');
            formData.append('notif_id', notifId);

            fetch('api/notifications.php', {
                method: 'POST',
                body: formData
            })
            .then(() => {
                window.location.href = url;
            })
            .catch(() => {
                window.location.href = url; // Fallback
            });
        }
    </script>
</body>
</html>
