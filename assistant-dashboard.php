<?php
/**
 * assistant-dashboard.php
 * Professional Grade Clinical Center - Health4Q
 * Aligned with Design Language in image_90371f.jpg
 */
require_once 'config.php';
requireRole(ROLE_ASSISTANT);

$pdo = getPDO();
$assistant_id = getCurrentRoleId();
$user_id = getCurrentUserId();

// 1. Fetch Assistant Profile with Error Handling
$stmt = $pdo->prepare('
    SELECT u.*, ca.clinic 
    FROM clinical_assistant ca
    JOIN users u ON ca.user_id = u.user_id
    WHERE ca.assistant_id = ?
');
$stmt->execute([$assistant_id]);
$assistant_data = $stmt->fetch();

// FIX: Handle cases where the database returns false (no record found)
// This prevents the "Trying to access array offset on value of type bool" warning.
if (!$assistant_data) {
    $assistant = [
        'first_name' => 'Assistant',
        'last_name'  => '',
        'clinic'     => 'General Clinic'
    ];
} else {
    $assistant = $assistant_data;
}

$today = date('Y-m-d');

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ops Center | Health4Q</title>
    <!-- Professional Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&family=Inter:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #1b4332;
            --secondary: #2d6a4f;
            --accent: #40916c;
            --bg-mint: #d8f3dc;
            --surface: #ffffff;
            --danger: #e63946;
            --text-main: #1a1a1a;
            --text-muted: #6b7280;
        }

        body {
            font-family: 'Quicksand', sans-serif;
            background: linear-gradient(135deg, var(--bg-mint) 0%, #f0f4f2 100%);
            margin: 0;
            min-height: 100vh;
            color: var(--text-main);
        }

        /* Header Style aligned with image_90371f.jpg */
        header {
            background: var(--primary);
            padding: 12px 60px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .logo-area { display: flex; align-items: center; gap: 12px; color: white; }
        .nav-brand img { height: 40px; filter: brightness(0) invert(1); }

        .nav-links { display: flex; gap: 8px; }
        .nav-btn {
            color: rgba(255,255,255,0.8);
            padding: 10px 18px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: 0.2s;
        }

        .nav-btn:hover { background: rgba(255,255,255,0.1); color: white; }
        .nav-btn.active { background: var(--secondary); color: white; }
        .logout-btn { background: #bc4749; color: white; margin-left: 10px; }

        .container { max-width: 1200px; margin: 40px auto; padding: 0 30px; }

        /* Welcome Section */
        .welcome-card {
            background: var(--surface);
            padding: 35px;
            border-radius: 24px;
            box-shadow: 0 10px 30px rgba(27, 67, 50, 0.08);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .welcome-text h1 { margin: 0; font-size: 1.8rem; color: var(--primary); }
        .welcome-text p { margin: 5px 0 0; color: var(--text-muted); font-family: 'Inter', sans-serif; }
        
        #clock { font-size: 1.6rem; font-weight: 700; color: var(--secondary); }

        /* Metrics Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: var(--surface);
            border-radius: 20px;
            padding: 25px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            transition: transform 0.3s ease;
        }

        .stat-card:hover { transform: translateY(-5px); }
        
        .stat-icon { 
            width: 45px; height: 45px; background: #f0f7f4; color: var(--secondary); 
            border-radius: 12px; display: flex; align-items: center; justify-content: center; 
            margin: 0 auto 15px; font-size: 1.1rem;
        }

        .stat-card h4 { color: var(--text-muted); font-size: 0.8rem; margin: 0; text-transform: uppercase; }
        .stat-card .value { font-size: 2.4rem; font-weight: 800; color: var(--primary); margin: 10px 0; }
        
        .stat-card.danger .value { color: var(--danger); }
        .stat-card.danger .stat-icon { background: #fff5f5; color: var(--danger); }

        /* Quick Access Bar from image_90371f.jpg */
        .quick-access {
            background: var(--surface);
            padding: 25px;
            border-radius: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.02);
        }

        .qa-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-top: 15px;
        }

        .qa-link {
            background: var(--primary);
            color: white;
            padding: 14px;
            border-radius: 12px;
            text-decoration: none;
            text-align: center;
            font-weight: 600;
            font-size: 0.9rem;
            transition: 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .qa-link:hover { background: var(--secondary); }

        @media (max-width: 992px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            header { padding: 12px 25px; }
        }
    </style>
</head>
<body>

    <header>
        <div class="nav-brand">
            <img src="images/Logo_only.png" alt="Health4Q">
        </div>
        <nav class="nav-links">
            <a href="assistant-dashboard.php" class="nav-btn active">Dashboard</a>
            <a href="assistant-queue.php" class="nav-btn">Queue</a>
            <a href="assistant-inventory.php" class="nav-btn">Inventory</a>
            <a href="logout.php" class="nav-btn logout-btn">Logout</a>
        </nav>
    </header>

    <div class="container">
        <!-- Banner -->
        <div class="welcome-card">
            <div class="welcome-text">
                <h1>Welcome Back, <?php echo htmlspecialchars($assistant['first_name']); ?>! 👋</h1>
                <p><i class="fa-solid fa-hospital"></i> Assigned: <strong><?php echo htmlspecialchars($assistant['clinic']); ?></strong></p>
            </div>
            <div style="text-align: right;">
                <div id="clock">00:00:00</div>
                <div style="font-size: 0.8rem; color: #888;"><?php echo date('l, F j, Y'); ?></div>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-user-clock"></i></div>
                <h4>Awaiting</h4>
                <div class="value"><?php echo $stats['awaiting']; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-check-double"></i></div>
                <h4>Cleared Today</h4>
                <div class="value"><?php echo $stats['cleared']; ?></div>
            </div>

            <div class="stat-card <?php echo $stats['low_stock'] > 0 ? 'danger' : ''; ?>">
                <div class="stat-icon"><i class="fa-solid fa-box-open"></i></div>
                <h4>Low Inventory</h4>
                <div class="value"><?php echo $stats['low_stock']; ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-clipboard-list"></i></div>
                <h4>Task Backlog</h4>
                <div class="value"><?php echo $stats['pending_tasks']; ?></div>
            </div>
        </div>

        <!-- Quick Access -->
        <div class="quick-access">
            <div style="font-weight: 700; color: var(--primary);"><i class="fa-solid fa-bolt"></i> Quick Operations</div>
            <div class="qa-grid">
                <a href="assistant-referral.php" class="qa-link"><i class="fa-solid fa-file-medical"></i> Draft Referral</a>
                <a href="assistant-patient-search.php" class="qa-link"><i class="fa-solid fa-search"></i> Patient Records</a>
                <a href="assistant-broadcast.php" class="qa-link"><i class="fa-solid fa-bullhorn"></i> Clinic Broadcast</a>
                <a href="assistant-inventory.php" class="qa-link"><i class="fa-solid fa-boxes-stacked"></i> Update Supplies</a>
            </div>
        </div>
    </div>

    <script>
        function updateClock() {
            const now = new Date();
            document.getElementById('clock').textContent = now.toLocaleTimeString('en-US', { 
                hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true 
            });
        }
        setInterval(updateClock, 1000);
        updateClock();
    </script>
</body>
</html>