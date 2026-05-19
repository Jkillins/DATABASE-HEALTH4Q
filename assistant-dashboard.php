<?php
/**
 * assistant-dashboard.php - Premium Ops, Interactive Task & Inventory Management Dashboard
 */
require_once 'config.php';
requireRole(ROLE_ASSISTANT);

$pdo = getPDO();
$assistant_id = getCurrentRoleId();
$user_id = getCurrentUserId();
$today = date('Y-m-d');

// ==========================================
// POST ACTION CONTROLLER (TASKS & INVENTORY)
// ==========================================
$post_action_success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // 1. Complete Task
        if (isset($_POST['action']) && $_POST['action'] === 'complete_task') {
            $task_id = (int)$_POST['task_id'];
            $stmt = $pdo->prepare("UPDATE assistant_tasks SET status = 'completed' WHERE id = ?");
            $stmt->execute([$task_id]);
            $_SESSION['active_modal'] = 'tasks';
            header("Location: assistant-dashboard.php");
            exit;
        }
        
        // 2. Add Task
        if (isset($_POST['action']) && $_POST['action'] === 'add_task') {
            $task_name = trim(sanitize($_POST['task_name'] ?? ''));
            if ($task_name !== '') {
                $stmt = $pdo->prepare("INSERT INTO assistant_tasks (task_name, status) VALUES (?, 'pending')");
                $stmt->execute([$task_name]);
            }
            $_SESSION['active_modal'] = 'tasks';
            header("Location: assistant-dashboard.php");
            exit;
        }

        // 3. Quick Inventory Replenish (+10 Units)
        if (isset($_POST['action']) && $_POST['action'] === 'quick_restock') {
            $item_id = (int)$_POST['item_id'];
            $stmt = $pdo->prepare("UPDATE inventory SET stock_level = stock_level + 10 WHERE id = ?");
            $stmt->execute([$item_id]);
            $_SESSION['active_modal'] = 'supplies';
            header("Location: assistant-dashboard.php");
            exit;
        }
    } catch (Exception $e) {
        // Fail silently or handle
    }
}

// Check if a modal was active before redirecting
$active_modal = $_SESSION['active_modal'] ?? '';
unset($_SESSION['active_modal']);

// 1. Fetch Assistant Profile
try {
    $stmt = $pdo->prepare('
        SELECT u.*, ca.clinic 
        FROM clinical_assistant ca
        JOIN users u ON ca.user_id = u.user_id
        WHERE ca.assistant_id = ?
    ');
    $stmt->execute([$assistant_id]);
    $assistant_data = $stmt->fetch();
} catch (Exception $e) {
    $assistant_data = false;
}

if (!$assistant_data) {
    $assistant = [
        'first_name' => 'Assistant',
        'last_name'  => '',
        'clinic'     => 'General Clinic'
    ];
} else {
    $assistant = $assistant_data;
}

/**
 * HELPER: Safe Query execution to prevent errors on empty tables
 */
function getCount($pdo, $sql) {
    try {
        return $pdo->query($sql)->fetchColumn() ?: 0;
    } catch (Exception $e) {
        return 0;
    }
}

// 2. Metrics Aggregation
$stats = [
    'awaiting'      => getCount($pdo, "SELECT COUNT(*) FROM patient_queue WHERE DATE(check_in_time) = '$today' AND status IN ('waiting', 'called')"),
    'cleared'       => getCount($pdo, "SELECT COUNT(*) FROM patient_queue WHERE DATE(check_in_time) = '$today' AND status = 'completed'"),
    'low_stock'     => getCount($pdo, "SELECT COUNT(*) FROM inventory WHERE stock_level <= reorder_level"),
    'pending_tasks' => getCount($pdo, "SELECT COUNT(*) FROM assistant_tasks WHERE status = 'pending'")
];

// 3. Fetch Live Queue Quick View (Next 3 waiting patients)
try {
    $stmtQueue = $pdo->prepare("
        SELECT pq.*, u_p.first_name as pfname, u_p.last_name as plname, u_d.last_name as dlname
        FROM patient_queue pq
        JOIN patient p ON pq.patient_id = p.patient_id
        JOIN users u_p ON p.user_id = u_p.user_id
        JOIN doctor d ON pq.doctor_id = d.doctor_id
        JOIN users u_d ON d.user_id = u_d.user_id
        WHERE DATE(pq.check_in_time) = ? AND pq.status IN ('waiting', 'called', 'in-progress')
        ORDER BY pq.queue_position ASC
        LIMIT 3
    ");
    $stmtQueue->execute([$today]);
    $live_queue = $stmtQueue->fetchAll();
} catch (Exception $e) {
    $live_queue = [];
}

// 4. Fetch Pending Checklist Tasks
try {
    $stmtTasks = $pdo->prepare("
        SELECT * FROM assistant_tasks 
        WHERE status = 'pending' 
        ORDER BY id DESC
    ");
    $stmtTasks->execute();
    $pending_tasks_list = $stmtTasks->fetchAll();
} catch (Exception $e) {
    $pending_tasks_list = [];
}

// 5. Fetch Critical Low Stock Items
try {
    $stmtStock = $pdo->prepare("
        SELECT id, item_name, stock_level, reorder_level 
        FROM inventory 
        WHERE stock_level <= reorder_level 
        ORDER BY stock_level ASC 
    ");
    $stmtStock->execute();
    $low_stock_items = $stmtStock->fetchAll();
} catch (Exception $e) {
    $low_stock_items = [];
}

// 6. Fetch Cleared Patients Today
try {
    $stmtCleared = $pdo->prepare("
        SELECT pq.*, u_p.first_name as pfname, u_p.last_name as plname
        FROM patient_queue pq
        JOIN patient p ON pq.patient_id = p.patient_id
        JOIN users u_p ON p.user_id = u_p.user_id
        WHERE DATE(pq.check_in_time) = ? AND pq.status = 'completed'
        ORDER BY pq.check_out_time DESC
    ");
    $stmtCleared->execute([$today]);
    $cleared_patients_list = $stmtCleared->fetchAll();
} catch (Exception $e) {
    $cleared_patients_list = [];
}

// 7. Fetch Recent Broadcast Alerts
try {
    $stmtAlerts = $pdo->prepare("
        SELECT title, priority, created_at 
        FROM broadcast_message 
        WHERE expires_at > NOW() 
        ORDER BY created_at DESC 
        LIMIT 2
    ");
    $stmtAlerts->execute();
    $recent_alerts = $stmtAlerts->fetchAll();
} catch (Exception $e) {
    $recent_alerts = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinical Assistant Dashboard | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #164e33;       /* unified premium forest green */
            --secondary: #2d6a4f;
            --accent: #40916c;
            --bg-mint: #d8f3dc;
            --surface: #ffffff;
            --danger: #e63946;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --warning: #f59e0b;
            --success: #16a34a;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Quicksand', sans-serif;
            background: url('images/Background_color.png') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            color: var(--text-main);
            position: relative;
            overflow-x: hidden;
        }

        header {
            background: var(--primary);
            padding: 0.8rem 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.06);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .nav-brand img { height: 32px; filter: brightness(0) invert(1); }

        .nav-links { display: flex; gap: 8px; align-items: center; }
        .nav-btn {
            color: rgba(255,255,255,0.85);
            padding: 8px 16px;
            border-radius: 20px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 700;
            transition: 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .nav-btn:hover { background: rgba(255,255,255,0.15); color: white; }
        .nav-btn.active { background: rgba(255,255,255,0.2); color: white; }
        .logout-btn { background: #d90429 !important; color: white !important; font-weight: 700; margin-left: 10px; }

        .container { max-width: 1200px; margin: 2.5rem auto; padding: 0 1.5rem; }

        .welcome-card {
            background: var(--surface);
            padding: 2.5rem 3rem;
            border-radius: 24px;
            box-shadow: 0 15px 35px rgba(22, 78, 51, 0.04);
            border: 1px solid rgba(255, 255, 255, 0.8);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .welcome-text h1 { margin: 0; font-size: 1.85rem; color: var(--primary); font-weight: 800; }
        .welcome-text p { margin: 5px 0 0; color: var(--text-muted); font-weight: 600; font-family: inherit; }
        
        #clock { font-size: 1.75rem; font-weight: 800; color: var(--secondary); letter-spacing: 0.5px; }

        /* Stats Grid with Interactive Modals hover triggers */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--surface);
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.8);
            padding: 25px;
            text-align: center;
            box-shadow: 0 15px 35px rgba(22, 78, 51, 0.04);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card.interactive {
            cursor: pointer;
        }

        .stat-card.interactive:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px rgba(22, 78, 51, 0.08);
        }

        .interactive-badge {
            font-size: 9px;
            font-weight: 700;
            color: var(--text-muted);
            background: #f3f4f6;
            padding: 3px 8px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            margin-top: 5px;
            opacity: 0.7;
            transition: 0.2s;
        }
        .stat-card.interactive:hover .interactive-badge {
            background: var(--secondary);
            color: white;
            opacity: 1;
        }
        
        .stat-icon { 
            width: 45px; height: 45px; background: #f0f7f4; color: var(--secondary); 
            border-radius: 12px; display: flex; align-items: center; justify-content: center; 
            margin: 0 auto 15px; font-size: 1.1rem;
        }

        .stat-card h4 { color: var(--text-muted); font-size: 0.8rem; margin: 0; text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; }
        .stat-card .value { font-size: 2.5rem; font-weight: 800; color: var(--primary); margin: 10px 0; }
        
        .stat-card.danger .value { color: var(--danger); }
        .stat-card.danger .stat-icon { background: #fff5f5; color: var(--danger); }

        .ops-grid {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 25px;
            margin-bottom: 30px;
        }

        @media (max-width: 900px) {
            .ops-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            header { padding: 12px 20px; }
        }

        .card {
            background: var(--surface);
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.8);
            padding: 25px;
            box-shadow: 0 15px 35px rgba(22, 78, 51, 0.04);
            margin-bottom: 25px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 40px rgba(22, 78, 51, 0.08);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #f3f4f6;
            padding-bottom: 12px;
            margin-bottom: 18px;
        }

        .card-title {
            font-size: 15px;
            font-weight: 800;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-link {
            font-size: 12px;
            font-weight: 700;
            color: var(--accent);
            text-decoration: none;
        }
        .card-link:hover { text-decoration: underline; }

        .list-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f9fafb;
            font-size: 13px;
        }
        .list-item:last-child { border-bottom: none; }

        .patient-badge {
            background: #e0f2fe;
            color: #0369a1;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
        }

        .status-pill {
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .status-waiting { background: #fff3cd; color: #856404; }
        .status-called { background: #cfe2ff; color: #084298; }
        .status-in-progress { background: #d1ecf1; color: #0c5460; }
        .status-completed { background: #d1e7dd; color: #0f5132; }

        /* Checklist Task Manager inside modal */
        .task-input-bar {
            display: flex;
            gap: 8px;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1.5px solid #f3f4f6;
        }

        .task-input-bar input {
            flex: 1;
            padding: 10px 14px;
            border: 1.5px solid #d1d5db;
            border-radius: 10px;
            font-family: inherit;
            font-size: 13px;
        }

        .task-input-bar input:focus {
            outline: none;
            border-color: var(--accent);
        }

        .task-add-btn {
            background: var(--secondary);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: 0.2s;
        }
        .task-add-btn:hover { background: var(--primary); }

        .task-complete-btn {
            background: #e6f9f0;
            color: var(--success);
            border: 1px solid #c7f3de;
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
        }
        .task-complete-btn:hover { background: var(--success); color: white; }

        /* Stock Items Widgets */
        .stock-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fff5f5;
            border: 1px solid #fee2e2;
            padding: 10px 15px;
            border-radius: 12px;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .stock-level-badge {
            background: var(--danger);
            color: white;
            padding: 2px 8px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 10px;
        }

        .btn-restock {
            background: #fee2e2;
            color: var(--danger);
            border: 1px solid #fecdd3;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
        }
        .btn-restock:hover { background: var(--danger); color: white; }

        /* Premium Modal Layout & Blur */
        .custom-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 34, 25, 0.4);
            backdrop-filter: blur(8px);
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .custom-modal.show {
            display: flex;
            opacity: 1;
        }

        .modal-card {
            background: var(--surface);
            width: 520px;
            max-width: 90%;
            border-radius: 24px;
            padding: 30px;
            box-shadow: 0 20px 50px rgba(15, 34, 25, 0.18);
            transform: scale(0.9);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(255,255,255,0.7);
        }

        .custom-modal.show .modal-card {
            transform: scale(1);
        }

        .modal-header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .modal-close-btn {
            background: #f3f4f6;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            color: var(--text-muted);
            transition: 0.2s;
        }
        .modal-close-btn:hover { background: var(--danger); color: white; }

        .modal-list-container {
            max-height: 320px;
            overflow-y: auto;
            padding-right: 5px;
        }

        .quick-access {
            background: var(--surface);
            padding: 25px;
            border-radius: 24px;
            border: 1px solid rgba(255, 255, 255, 0.8);
            box-shadow: 0 15px 35px rgba(22, 78, 51, 0.04);
            margin-bottom: 25px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .quick-access:hover {
            transform: translateY(-2px);
            box-shadow: 0 20px 40px rgba(22, 78, 51, 0.08);
        }

        .qa-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-top: 15px;
        }

        .qa-link {
            background: #164e33; /* solid forest green matching mockup */
            color: white;
            padding: 14px;
            border-radius: 12px;
            text-decoration: none;
            text-align: center;
            font-weight: 700;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 6px rgba(22, 78, 51, 0.1);
        }

        .qa-link:hover { 
            background: #0f3623; 
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(22, 78, 51, 0.2);
        }
    </style>
</head>
<body>

    <header>
        <div class="nav-brand">
            <img src="images/Logo_only.png" alt="Health4Q">
        </div>
        <nav class="nav-links">
            <a href="assistant-dashboard.php" class="nav-btn active">Overview</a>
            <a href="assistant-queue.php" class="nav-btn">📋 Live Queue</a>
            <a href="assistant-broadcast.php" class="nav-btn">📢 Alerts</a>
            <a href="assistant-referral.php" class="nav-btn">📤 Referrals</a>
            <a href="assistant-inventory.php" class="nav-btn">📦 Supplies</a>
            <a href="assistant-patient-search.php" class="nav-btn">🔍 Search</a>
            
            <!-- Real-Time Notification Panel -->
            <div class="notification-container" style="position: relative; display: inline-block; margin-left: 10px;">
                <button id="notifBell" style="background: none; border: none; font-size: 1.2rem; color: white; cursor: pointer; position: relative; padding: 8px; display: flex; align-items: center; justify-content: center; transition: 0.3s; border-radius: 8px;">
                    🔔<span id="notifBadge" style="display: none; position: absolute; top: -2px; right: -2px; background: #d90429; color: white; font-size: 0.65rem; font-weight: 800; padding: 2px 6px; border-radius: 50%; min-width: 16px; text-align: center; border: 2px solid var(--primary-green);">0</span>
                </button>
                <div id="notifDropdown" style="display: none; position: absolute; right: 0; top: 45px; background: white; width: 320px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); border: 1px solid rgba(0,0,0,0.08); z-index: 9999; overflow: hidden; animation: slideDown 0.3s ease;">
                    <div style="padding: 12px 15px; background: var(--accent-green); color: white; font-weight: 700; font-size: 0.85rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(0,0,0,0.05);">
                        <span>🔔 Clinic Notifications</span>
                        <button onclick="markAllNotificationsAsRead()" style="background: none; border: none; color: #d8f3dc; font-size: 0.75rem; font-weight: 700; cursor: pointer; text-decoration: underline;">Mark read</button>
                    </div>
                    <div id="notifList" style="max-height: 280px; overflow-y: auto; padding: 5px 0;">
                        <p style="text-align: center; color: #888; font-size: 0.8rem; padding: 20px 10px;">Loading notifications...</p>
                    </div>
                </div>
            </div>

            <a href="logout.php" class="nav-btn logout-btn" style="background: var(--danger); color: white; font-weight: 700; margin-left: 15px;"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </nav>
    </header>

    <div class="container">
        <!-- Banner -->
        <div class="welcome-card">
            <div class="welcome-text">
                <h1>Welcome Back, <?php echo htmlspecialchars($assistant['first_name']); ?>! 👋</h1>
                <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px;">
                    <span style="display: inline-flex; align-items: center; gap: 6px; background: #e0f2fe; border: 1px solid #bae6fd; color: #0369a1; padding: 5px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 700; box-shadow: 0 2px 4px rgba(3, 105, 161, 0.04);">
                        🏥 <?php echo htmlspecialchars($assistant['clinic']); ?>
                    </span>
                    <span style="display: inline-flex; align-items: center; gap: 6px; background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; padding: 5px 12px; border-radius: 12px; font-size: 0.75rem; font-weight: 700; box-shadow: 0 2px 4px rgba(37, 99, 235, 0.05);">
                        📅 <?php echo date('l, F j, Y'); ?>
                    </span>
                </div>
            </div>
            <div style="text-align: right; display: flex; flex-direction: column; align-items: flex-end; gap: 4px;">
                <div id="clock" style="background: rgba(22, 78, 51, 0.06); padding: 6px 16px; border-radius: 12px; font-weight: 800; border: 1px solid rgba(22, 78, 51, 0.1);">00:00:00</div>
                <small style="color: var(--text-muted); font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Active Session Time</small>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-user-clock"></i></div>
                <h4>Awaiting Today</h4>
                <div class="value"><?php echo $stats['awaiting']; ?></div>
            </div>

            <!-- Cleared Today Card -->
            <div class="stat-card interactive" onclick="openModal('clearedModal')">
                <div class="stat-icon" style="background:#e8fdf5; color:var(--success);"><i class="fa-solid fa-check-double"></i></div>
                <h4>Cleared Today</h4>
                <div class="value" style="color: var(--success);"><?php echo $stats['cleared']; ?></div>
                <div class="interactive-badge"><i class="fa-solid fa-arrow-pointer"></i> Click to view</div>
            </div>

            <!-- Low Inventory Card -->
            <div class="stat-card interactive <?php echo $stats['low_stock'] > 0 ? 'danger' : ''; ?>" onclick="openModal('suppliesModal')">
                <div class="stat-icon"><i class="fa-solid fa-box-open"></i></div>
                <h4>Low Inventory</h4>
                <div class="value"><?php echo $stats['low_stock']; ?></div>
                <div class="interactive-badge"><i class="fa-solid fa-arrow-pointer"></i> Click to view</div>
            </div>

            <!-- Task Backlog Card -->
            <div class="stat-card interactive" onclick="openModal('tasksModal')">
                <div class="stat-icon" style="background:#fff9eb; color:var(--warning);"><i class="fa-solid fa-clipboard-list"></i></div>
                <h4>Task Backlog</h4>
                <div class="value" style="color: var(--warning);"><?php echo $stats['pending_tasks']; ?></div>
                <div class="interactive-badge"><i class="fa-solid fa-arrow-pointer"></i> Click to view</div>
            </div>
        </div>

        <!-- Operational Desk Content split in columns -->
        <div class="ops-grid">
            
            <!-- Left Side: Today's Queue -->
            <div>
                <!-- Queue tracker -->
                <div class="card" style="border-top: 4px solid var(--primary);">
                    <div class="card-header">
                        <div class="card-title"><i class="fa-solid fa-users-rectangle"></i> Today's Active Queue Tracker</div>
                        <a href="assistant-queue.php" class="card-link">Manage Queue →</a>
                    </div>
                    <?php if (count($live_queue) > 0): ?>
                        <?php foreach ($live_queue as $q): ?>
                            <div class="list-item">
                                <div>
                                    <span class="patient-badge">Ticket #<?php echo $q['queue_position']; ?></span>
                                    <strong style="margin-left: 10px; color: var(--primary);"><?php echo htmlspecialchars($q['pfname'] . ' ' . $q['plname']); ?></strong>
                                </div>
                                <div style="display:flex; align-items:center; gap: 15px;">
                                    <span style="font-size: 11px; color:#666;">Doc: <?php echo htmlspecialchars($q['dlname']); ?></span>
                                    <span class="status-pill status-<?php echo strtolower($q['status']); ?>">
                                        <?php echo htmlspecialchars($q['status']); ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color: #aaa; text-align:center; padding: 25px 0; font-size:12px;">No active patients in the queue right now.</p>
                    <?php endif; ?>
                </div>

                <!-- Operations Desk Actions -->
                <div class="quick-access">
                    <div style="font-weight: 700; color: var(--primary);"><i class="fa-solid fa-bolt"></i> Operations Quick Desk</div>
                    <div class="qa-grid">
                        <a href="assistant-referral.php" class="qa-link"><i class="fa-solid fa-file-medical"></i> Draft Referral</a>
                        <a href="assistant-patient-search.php" class="qa-link"><i class="fa-solid fa-search"></i> Patient Records</a>
                        <a href="assistant-broadcast.php" class="qa-link"><i class="fa-solid fa-bullhorn"></i> Clinic Broadcast</a>
                        <a href="assistant-queue.php" class="qa-link"><i class="fa-solid fa-users-rectangle"></i> Clinic Queue</a>
                    </div>
                </div>
            </div>

            <!-- Right Side: Recent Announcements -->
            <div>
                <!-- Recent Announcements Card -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fa-solid fa-bullhorn"></i> Broadcast Announcements</div>
                        <a href="assistant-broadcast.php" class="card-link">Send Broadcast →</a>
                    </div>
                    <?php if (count($recent_alerts) > 0): ?>
                        <?php foreach ($recent_alerts as $alert): ?>
                            <div class="list-item" style="flex-direction: column; align-items: flex-start; gap: 4px;">
                                <div style="display:flex; justify-content:space-between; width:100%; font-size:12px;">
                                    <strong>📢 <?php echo htmlspecialchars($alert['title']); ?></strong>
                                    <span style="font-size: 9px; font-weight:800; text-transform:uppercase; color: <?php echo $alert['priority'] === 'urgent' ? 'var(--danger)' : 'var(--accent)'; ?>">
                                        <?php echo $alert['priority']; ?>
                                    </span>
                                </div>
                                <span style="font-size: 10px; color:#888;"><?php echo date('M d, Y h:i A', strtotime($alert['created_at'])); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color: #aaa; text-align:center; padding: 25px 0; font-size:12px;">No active broadcasts currently running.</p>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <!-- ==============================================
         1. TASK CHECKLIST BACKLOG MODAL
         ============================================== -->
    <div id="tasksModal" class="custom-modal" onclick="closeModalOnBackdrop(event, 'tasksModal')">
        <div class="modal-card">
            <div class="modal-header-section">
                <h3 class="card-title" style="font-size: 18px; color: var(--primary);"><i class="fa-solid fa-clipboard-list" style="color: var(--warning);"></i> Clinical Task Checklist Backlog</h3>
                <button class="modal-close-btn" onclick="closeModal('tasksModal')">&times;</button>
            </div>
            
            <div class="modal-list-container">
                <?php if (count($pending_tasks_list) > 0): ?>
                    <?php foreach ($pending_tasks_list as $task): ?>
                        <div class="list-item" style="padding: 14px 0;">
                            <span style="font-weight: 600; color: var(--primary);"><i class="fa-regular fa-square" style="margin-right: 10px; color: var(--warning);"></i> <?php echo htmlspecialchars($task['task_name']); ?></span>
                            <form method="POST" style="margin: 0;">
                                <input type="hidden" name="action" value="complete_task">
                                <input type="hidden" name="task_id" value="<?php echo $task['id']; ?>">
                                <button type="submit" class="task-complete-btn"><i class="fa-solid fa-check"></i> Done</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 30px 0;">
                        <span style="font-size: 40px;">🎉</span>
                        <h4 style="color: var(--success); margin-top: 10px;">Checklist Backlog Cleared!</h4>
                        <p style="font-size: 12px; color: var(--text-muted); margin-top: 5px;">All outstanding chores have been resolved.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Quick Add Task form -->
            <form method="POST" class="task-input-bar">
                <input type="hidden" name="action" value="add_task">
                <input type="text" name="task_name" placeholder="Type a reminder (e.g. Clean room 4)..." required>
                <button type="submit" class="task-add-btn">+ Add Task</button>
            </form>
        </div>
    </div>

    <!-- ==============================================
         2. CRITICAL LOW STOCK SUPPLIES MODAL
         ============================================== -->
    <div id="suppliesModal" class="custom-modal" onclick="closeModalOnBackdrop(event, 'suppliesModal')">
        <div class="modal-card">
            <div class="modal-header-section">
                <h3 class="card-title" style="font-size: 18px; color: var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Critical Supply Stock Watchdog</h3>
                <button class="modal-close-btn" onclick="closeModal('suppliesModal')">&times;</button>
            </div>
            
            <div class="modal-list-container">
                <?php if (count($low_stock_items) > 0): ?>
                    <?php foreach ($low_stock_items as $item): ?>
                        <div class="stock-item">
                            <div>
                                <strong>📦 <?php echo htmlspecialchars($item['item_name']); ?></strong>
                                <div style="font-size: 10px; color:#c1121f; font-weight:700; margin-top:2px;">Remaining: <?php echo $item['stock_level']; ?> / Min Level: <?php echo $item['reorder_level']; ?></div>
                            </div>
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="action" value="quick_restock">
                                <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                <button type="submit" class="btn-restock"><i class="fa-solid fa-truck-ramp-box"></i> +10 Stock</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 30px 0;">
                        <span style="font-size: 40px; color: var(--success);"><i class="fa-solid fa-shield-halved"></i></span>
                        <h4 style="color: var(--success); margin-top: 15px;">Inventory is Healthy!</h4>
                        <p style="font-size: 12px; color: var(--text-muted); margin-top: 5px;">No items are currently below minimum reorder levels.</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <div style="text-align: right; margin-top: 20px; padding-top: 15px; border-top: 1.5px solid #f3f4f6;">
                <a href="assistant-inventory.php" class="card-link" style="font-size: 13px;">Manage Complete Supply Catalog →</a>
            </div>
        </div>
    </div>

    <!-- ==============================================
         3. CLEARED PATIENTS LOG MODAL
         ============================================== -->
    <div id="clearedModal" class="custom-modal" onclick="closeModalOnBackdrop(event, 'clearedModal')">
        <div class="modal-card">
            <div class="modal-header-section">
                <h3 class="card-title" style="font-size: 18px; color: var(--success);"><i class="fa-solid fa-clipboard-check"></i> Cleared Patient Checkout Log</h3>
                <button class="modal-close-btn" onclick="closeModal('clearedModal')">&times;</button>
            </div>
            
            <div class="modal-list-container">
                <?php if (count($cleared_patients_list) > 0): ?>
                    <?php foreach ($cleared_patients_list as $c): ?>
                        <div class="list-item" style="padding: 14px 0;">
                            <div>
                                <strong style="color: var(--primary); font-size:14px;">👤 <?php echo htmlspecialchars($c['pfname'] . ' ' . $c['plname']); ?></strong>
                                <div style="font-size: 10px; color: var(--text-muted); margin-top: 2px;">Consultation Completed and Cleared</div>
                            </div>
                            <div style="display:flex; align-items:center; gap: 10px;">
                                <span style="font-size: 11px; color:#666;">Out: <strong><?php echo date('h:i A', strtotime($c['check_out_time'])); ?></strong></span>
                                <span class="status-pill status-completed">Completed</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="color: #aaa; text-align:center; padding: 30px 0; font-size:12px;">No patients checked out and cleared yet today.</p>
                <?php endif; ?>
            </div>
            
            <div style="text-align: right; margin-top: 20px; padding-top: 15px; border-top: 1.5px solid #f3f4f6;">
                <a href="assistant-queue.php" class="card-link" style="font-size: 13px;">Review Full Live Queue History →</a>
            </div>
        </div>
    </div>

    <!-- ==============================================
         SCRIPTS & MODAL TOGGLERS
         ============================================== -->
    <script>
        function updateClock() {
            const now = new Date();
            document.getElementById('clock').textContent = now.toLocaleTimeString('en-US', { 
                hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true 
            });
        }
        setInterval(updateClock, 1000);
        updateClock();

        // Modal triggers
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'flex';
                // Trigger reflow for CSS animations
                void modal.offsetWidth;
                modal.classList.add('show');
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('show');
                setTimeout(() => {
                    modal.style.display = 'none';
                }, 300); // Match animation length
            }
        }

        function closeModalOnBackdrop(event, modalId) {
            if (event.target === document.getElementById(modalId)) {
                closeModal(modalId);
            }
        }

        // Maintain active modal state after form POST redirect
        window.addEventListener('DOMContentLoaded', () => {
            const activeModal = "<?php echo $active_modal; ?>";
            if (activeModal === 'tasks') {
                openModal('tasksModal');
            } else if (activeModal === 'supplies') {
                openModal('suppliesModal');
            }
        });

        function fetchNotifications() {
            fetch('api/notifications.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const badge = document.getElementById('notifBadge');
                        if (data.unread_count > 0) {
                            badge.innerText = data.unread_count;
                            badge.style.display = 'block';
                        } else {
                            badge.style.display = 'none';
                        }

                        const list = document.getElementById('notifList');
                        list.innerHTML = '';

                        if (data.notifications.length === 0) {
                            list.innerHTML = `<p style="text-align: center; color: #888; font-size: 0.8rem; padding: 20px 10px;">No notifications found.</p>`;
                        } else {
                            data.notifications.forEach(n => {
                                const isUnread = n.status === 'sent';
                                const item = document.createElement('div');
                                item.style.padding = '12px 15px';
                                item.style.borderBottom = '1px solid #f1f5f9';
                                item.style.background = isUnread ? '#f0fdf4' : 'white';
                                item.style.transition = '0.2s';
                                item.style.cursor = 'pointer';
                                item.innerHTML = `
                                    <div style="font-weight: 700; font-size: 0.8rem; color: var(--accent-green); margin-bottom: 2px;">
                                        ${isUnread ? '🟢 ' : ''}${n.subject}
                                    </div>
                                    <div style="font-size: 0.75rem; color: #4b5563; line-height: 1.4; margin-bottom: 4px;">
                                        ${n.body}
                                    </div>
                                    <div style="font-size: 0.65rem; color: #9ca3af; font-weight: 600;">
                                        ${new Date(n.sent_at).toLocaleString()}
                                    </div>
                                `;
                                list.appendChild(item);
                            });
                        }
                    }
                });
        }

        function markAllNotificationsAsRead() {
            const formData = new FormData();
            formData.append('action', 'mark_read');

            fetch('api/notifications.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    fetchNotifications();
                }
            });
        }

        document.getElementById('notifBell').addEventListener('click', (e) => {
            e.stopPropagation();
            const dropdown = document.getElementById('notifDropdown');
            dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
        });

        document.addEventListener('click', () => {
            document.getElementById('notifDropdown').style.display = 'none';
        });

        document.getElementById('notifDropdown').addEventListener('click', (e) => {
            e.stopPropagation();
        });

        // Run on load
        fetchNotifications();
        // Poll every 15 seconds
        setInterval(fetchNotifications, 15000);
    </script>
</body>
</html>