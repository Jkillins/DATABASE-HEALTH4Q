<?php
/**
 * doctor-dashboard.php
 */

require_once 'config.php';
requireRole(ROLE_DOCTOR); 

$pdo = getPDO();
$doctor_id = getCurrentRoleId();
if (!$doctor_id && isset($_SESSION['user_id'])) {
    $stmtRole = $pdo->prepare("SELECT doctor_id FROM doctor WHERE user_id = ?");
    $stmtRole->execute([$_SESSION['user_id']]);
    $doctor_id = $stmtRole->fetchColumn();
    if ($doctor_id) {
        $_SESSION['role_id'] = (int)$doctor_id;
    }
}
$doctor = getDoctorProfile($pdo, $doctor_id);

// --- HANDLE QUEUE STATUS UPDATE ---
$queue_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_queue_status') {
    $q_id = (int)$_POST['queue_id'];
    $status = $_POST['status'];
    
    if (in_array($status, ['waiting', 'called', 'in-progress', 'completed', 'canceled'])) {
        try {
            $update_data = [];
            if ($status === 'called') {
                $update_data = ['status' => $status, 'called_time' => date('Y-m-d H:i:s')];
            } elseif ($status === 'in-progress') {
                $update_data = ['status' => $status, 'seen_time' => date('Y-m-d H:i:s')];
            } elseif ($status === 'completed') {
                $update_data = ['status' => $status, 'check_out_time' => date('Y-m-d H:i:s')];
            } else {
                $update_data = ['status' => $status];
            }
            
            $set_clause = implode(', ', array_map(fn($k) => "$k = ?", array_keys($update_data)));
            $values = array_values($update_data);
            $values[] = $q_id;
            $values[] = $doctor_id;
            
            $stmt = $pdo->prepare("UPDATE patient_queue SET $set_clause WHERE queue_id = ? AND doctor_id = ?");
            if ($stmt->execute($values)) {
                $queue_msg = "✓ Live queue status updated successfully.";
            }
        } catch (Exception $e) {
            $queue_msg = "✗ Error updating queue: " . $e->getMessage();
        }
    }
}

// --- FETCH ACTIVE QUEUE FOR THIS DOCTOR ---
try {
    $stmtQ = $pdo->prepare("
        SELECT pq.queue_id, pq.queue_position, pq.status, pq.check_in_time, 
               u.first_name, u.last_name, u.email
        FROM patient_queue pq
        JOIN patient p ON pq.patient_id = p.patient_id
        JOIN users u ON p.user_id = u.user_id
        WHERE pq.doctor_id = ? 
          AND DATE(pq.check_in_time) = CURDATE() 
          AND pq.status IN ('waiting', 'called', 'in-progress')
        ORDER BY pq.queue_position ASC
    ");
    $stmtQ->execute([$doctor_id]);
    $active_queue_list = $stmtQ->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $active_queue_list = [];
}

// --- DYNAMIC CLINIC STATUS LOGIC ---
date_default_timezone_set('Asia/Manila'); 
$current_time = date('H:i');
$current_day = date('l');
$clinic_status = "Closed";
$status_color = "#ef4444"; 

$schedule_config = [
    'Monday'    => ['08:00 AM', '05:00 PM'],
    'Tuesday'   => ['08:00 AM', '05:00 PM'],
    'Wednesday' => ['08:00 AM', '05:00 PM'],
    'Thursday'  => ['08:00 AM', '05:00 PM'],
    'Friday'    => ['08:00 AM', '05:00 PM'],
    'Saturday'  => ['08:00 AM', '12:00 PM'],
    'Sunday'    => null
];

if (isset($schedule_config[$current_day]) && $schedule_config[$current_day]) {
    $hours = $schedule_config[$current_day];
    if ($current_time >= $hours[0] && $current_time <= $hours[1]) {
        $clinic_status = "Online / Open";
        $status_color = "#22c55e"; 
    }
}

// --- PHARMACY & INVENTORY LOGIC (FIXED) ---
try {
    // Fetch statistics directly from medicine table
    $stmtInv = $pdo->query("
        SELECT 
            COUNT(*) as total_skus,
            SUM(CASE WHEN stock_quantity > 0 THEN 1 ELSE 0 END) as in_stock,
            SUM(CASE WHEN stock_quantity <= 10 AND stock_quantity > 0 THEN 1 ELSE 0 END) as low_stock,
            SUM(stock_quantity) as total_units
        FROM medicine
    ");
    $inv = $stmtInv->fetch(PDO::FETCH_ASSOC);
    
    $medicine_count = $inv['total_skus'] ?: 0;
    $total_units = $inv['total_units'] !== null ? $inv['total_units'] : 0;
    $availability_rate = ($medicine_count > 0) ? round(($inv['in_stock'] / $medicine_count) * 100) : 0;
    $low_stock_count = $inv['low_stock'] ?: 0;

} catch (Exception $e) {
    $medicine_count = 0;
    $total_units = 0;
    $availability_rate = 0;
    $low_stock_count = 0;
}

// --- CLINIC INVENTORY SUPPLIES LOGIC ---
try {
    // Fetch count of low stock and total stock
    $stmtInvStats = $pdo->query("
        SELECT 
            COUNT(*) as total_items,
            SUM(stock_level) as total_qty,
            SUM(CASE WHEN stock_level <= reorder_level THEN 1 ELSE 0 END) as critical_count
        FROM inventory
    ");
    $invStats = $stmtInvStats->fetch(PDO::FETCH_ASSOC);
    
    $total_supply_items = $invStats['total_items'] ?: 0;
    $total_supply_qty = $invStats['total_qty'] !== null ? $invStats['total_qty'] : 0;
    $critical_supply_count = $invStats['critical_count'] ?: 0;

    // Fetch all supplies from inventory to show details, prioritized by critical status
    $stmtInvItems = $pdo->query("SELECT * FROM inventory ORDER BY (stock_level <= reorder_level) DESC, stock_level ASC LIMIT 5");
    $critical_supplies = $stmtInvItems->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $total_supply_items = 0;
    $total_supply_qty = 0;
    $critical_supply_count = 0;
    $critical_supplies = [];
}

// --- AUTO-MIGRATE APPOINTMENT STATUS ENUM ---
try {
    $pdo->exec("ALTER TABLE appointment MODIFY COLUMN status ENUM('pending', 'scheduled', 'in-progress', 'completed', 'canceled', 'no-show') DEFAULT 'pending'");
} catch (Exception $e) {
    // Column already modified or ignore
}

// --- APPOINTMENTS COUNTERS FOR THE APPOINTMENT CARD ---
$waiting_count = 0;
$completed_count = 0;
try {
    $stmtCounts = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN status IN ('pending', 'scheduled', 'in-progress') THEN 1 ELSE 0 END) as pending_qty,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_qty
        FROM appointment
        WHERE doctor_id = ?
    ");
    $stmtCounts->execute([$doctor_id]);
    $counts = $stmtCounts->fetch(PDO::FETCH_ASSOC);
    $waiting_count = (int)($counts['pending_qty'] ?? 0);
    $completed_count = (int)($counts['completed_qty'] ?? 0);
} catch (Exception $e) {
    $waiting_count = 0;
    $completed_count = 0;
}
$total_appointments = $waiting_count + $completed_count;
$appointment_completion_rate = ($total_appointments > 0) ? round(($completed_count / $total_appointments) * 100) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Dashboard | Professional Suite</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a4d34;
            --primary-light: #2d6a4f;
            --accent-gold: #b5835a;
            --purple-main: #7209b7;
            --bg-soft: #f8fafc;
            --white: #ffffff;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Quicksand', sans-serif; }

        body {
            background: url('images/Background_color.png') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            color: var(--text-main);
            line-height: 1.6;
            position: relative;
            overflow-x: hidden;
        }

        /* --- NAVIGATION --- */
        .top-nav {
            background: var(--primary);
            padding: 0.75rem 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky; top: 0; z-index: 1000;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .nav-links { display: flex; gap: 1rem; }
        .nav-links a {
            color: rgba(255,255,255,0.7); text-decoration: none;
            font-size: 0.85rem; font-weight: 600; padding: 0.5rem 1rem;
            border-radius: 8px; transition: all 0.3s ease;
        }
        .nav-links a.active, .nav-links a:hover { 
            background: rgba(255,255,255,0.1); color: white; }

        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1.5rem; }

        /* --- PROFESSIONAL HEADER --- */
        .welcome-card {
            background: var(--white);
            padding: 2.5rem;
            border-radius: 20px;
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            border-left: 6px solid var(--primary);
        }
        .dr-info h1 { font-size: 1.75rem; color: var(--primary); font-weight: 700; }
        .status-pill {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 4px 12px; background: #f1f5f9; border-radius: 20px;
            font-size: 0.8rem; font-weight: 700; margin-top: 8px;
        }
        .pulse { width: 8px; height: 8px; border-radius: 50%; animation: pulse-ring 1.5s infinite; }
        @keyframes pulse-ring {
            0% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0.4); }
            70% { box-shadow: 0 0 0 10px rgba(34, 197, 94, 0); }
            100% { box-shadow: 0 0 0 0 rgba(34, 197, 94, 0); }
        }

        /* --- DASHBOARD GRID --- */
        .main-layout-grid {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 1.5rem;
            align-items: start;
        }
        .dashboard-grid { display: grid; grid-template-columns: 320px 1fr; gap: 2rem; }
        .card { background: var(--white); border-radius: 16px; border: 1px solid var(--border); overflow: hidden; box-shadow: var(--shadow); }

        @media (max-width: 1024px) {
            .main-layout-grid {
                grid-template-columns: 1fr;
            }
        }

        /* --- CLINIC STATS HUB --- */
        .stats-hub { text-align: center; padding: 2.5rem 1.5rem; display: flex; flex-direction: column; align-items: center; }
        .hub-icon { 
            background: var(--primary); color: white; width: 64px; height: 64px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; margin-bottom: 1.25rem; box-shadow: 0 10px 15px -3px rgba(26, 77, 52, 0.2);
        }
        .hub-total { font-size: 4rem; font-weight: 800; color: var(--primary); line-height: 1; }
        .hub-label { font-size: 0.9rem; text-transform: uppercase; font-weight: 700; color: var(--text-muted); letter-spacing: 1px; margin-bottom: 2rem; }

        .hub-grid { display: grid; grid-template-columns: 1fr 1fr; width: 100%; border-top: 1px solid var(--border); padding-top: 1.5rem; }
        .hub-item { padding: 0 10px; }
        .hub-item:first-child { border-right: 1px solid var(--border); }
        .hub-val { display: block; font-size: 1.5rem; font-weight: 800; color: var(--text-main); }
        .hub-sub { font-size: 0.7rem; font-weight: 700; color: var(--text-muted); text-transform: uppercase; }

        /* --- SIDEBAR SCHEDULE --- */
        .card-header { background: var(--primary); color: white; padding: 1.25rem; font-weight: 700; font-size: 0.9rem; letter-spacing: 0.5px; }
        .schedule-row { display: flex; justify-content: space-between; padding: 0.75rem 1.25rem; border-bottom: 1px solid #f1f5f9; font-size: 0.85rem; }
        .schedule-row.today { background: #f0fff4; border-left: 4px solid var(--primary-light); color: var(--primary); font-weight: 700; }

        /* --- QUICK ACTIONS --- */
        .actions-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; margin-top: 2.5rem; }
        .action-link {
            background: #164e33;
            color: white;
            font-weight: 700;
            font-size: 0.82rem;
            text-decoration: none;
            padding: 12px 15px;
            border-radius: 12px;
            text-align: center;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            box-shadow: 0 4px 6px rgba(22, 78, 51, 0.15);
            border: none;
        }
        .action-link:hover {
            background: #0f3623;
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(22, 78, 51, 0.25);
        }

        /* --- OPERATING HOURS & SUPPLIES GRID --- */
        .operating-supplies-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        @media (max-width: 768px) {
            .operating-supplies-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 900px) {
            .dashboard-grid { grid-template-columns: 1fr; }
            .welcome-card { flex-direction: column; text-align: center; gap: 1.5rem; }
        }
    </style>
</head>
<body>

    <nav class="top-nav">
        <div class="brand">
            <img src="images/Logo_only.png" alt="Logo" style="height: 32px; filter: brightness(0) invert(1);">
        </div>
        <div class="nav-links">
            <a href="doctor-dashboard.php" class="active">🏠 Dashboard</a>
            <a href="doctor-patient-list.php">👥 Patients</a>
            <a href="doctor-appointment.php">📅 Appointments</a>
            <a href="doctor-medical-request.php">📁 Requests</a>
            <a href="doctor-prescriptions.php">💊 Medicine</a>
            <a href="doctor-availability.php">⏰ Availability</a>
            <a href="doctor-profile.php">⚙️ Profile</a>
        </div>
        <div style="display: flex; align-items: center; gap: 15px;">
            <!-- Real-Time Notification Panel -->
            <div class="notification-container" style="position: relative; display: inline-block;">
                <button id="notifBell" style="background: none; border: none; font-size: 1.2rem; color: white; cursor: pointer; position: relative; padding: 8px; display: flex; align-items: center; justify-content: center; transition: 0.3s; border-radius: 8px;">
                    🔔<span id="notifBadge" style="display: none; position: absolute; top: -2px; right: -2px; background: #d90429; color: white; font-size: 0.65rem; font-weight: 800; padding: 2px 6px; border-radius: 50%; min-width: 16px; text-align: center; border: 2px solid var(--header-green);">0</span>
                </button>
                <div id="notifDropdown" style="display: none; position: absolute; right: 0; top: 45px; background: white; width: 320px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.15); border: 1px solid rgba(0,0,0,0.08); z-index: 9999; overflow: hidden; animation: slideDown 0.3s ease;">
                    <div style="padding: 12px 15px; background: var(--header-green); color: white; font-weight: 700; font-size: 0.85rem; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(0,0,0,0.05);">
                        <span>🔔 Clinic Notifications</span>
                        <button onclick="markAllNotificationsAsRead()" style="background: none; border: none; color: #d8f3dc; font-size: 0.75rem; font-weight: 700; cursor: pointer; text-decoration: underline;">Mark read</button>
                    </div>
                    <div id="notifList" style="max-height: 280px; overflow-y: auto; padding: 5px 0;">
                        <p style="text-align: center; color: #888; font-size: 0.8rem; padding: 20px 10px;">Loading notifications...</p>
                    </div>
                </div>
            </div>
            <a href="logout.php" style="background-color: #e74c3c; color: white; font-size: 0.8rem; font-weight: 700; text-decoration: none; padding: 8px 16px; border-radius: 8px; transition: 0.3s;">Log Out</a>
        </div>
    </nav>

    <div class="container">
        <header class="welcome-card">
            <div class="dr-info">
                <h1>Dr. <?php echo htmlspecialchars(($doctor['first_name'] ?? '') . ' ' . ($doctor['last_name'] ?? 'Consultant')); ?></h1>
                <div class="status-pill">
                    <span class="pulse" style="background: <?php echo $status_color; ?>;"></span>
                    <span style="color: <?php echo $status_color; ?>;">Clinic is <?php echo $clinic_status; ?></span>
                </div>
            </div>
            <div style="text-align: right;">
                <p style="font-weight: 700; font-size: 1.1rem;"><?php echo date('l, d F Y'); ?></p>
                <p style="color: var(--text-muted); font-size: 0.85rem;">System Time: <?php echo date('h:i A'); ?></p>
            </div>
        </header>

        <!-- Success Message from Queue Updates -->
        <?php if (!empty($queue_msg)): ?>
            <div style="background: #e6f9f0; color: #16a34a; padding: 12px 20px; border-radius: 12px; margin-bottom: 1.5rem; font-weight: 700; border: 1.5px solid #c7f3de; font-size: 0.85rem;">
                <i class="fa-solid fa-circle-check"></i> <?php echo $queue_msg; ?>
            </div>
        <?php endif; ?>

        <!-- MAIN LAYOUT GRID -->
        <div class="main-layout-grid">
            
            <!-- LEFT COLUMN: LIVE QUEUE & SUPPLIES -->
            <div class="left-column" style="display: flex; flex-direction: column; gap: 1.5rem;">
                
                <!-- 📋 LIVE CLINIC QUEUE & PATIENT FLOW DESK -->
                <div class="card" style="padding: 20px; margin: 0;">
                    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--border); padding-bottom: 12px; margin-bottom: 15px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="font-size: 20px; display: inline-block;">📋</span>
                            <div>
                                <h2 style="font-size: 1.1rem; color: var(--primary); font-weight: 800; margin: 0;">Live Clinic Queue & Patient Flow</h2>
                                <p style="font-size: 0.7rem; color: var(--text-muted); font-weight: 600;">Monitor and advance patients currently checked into your active clinic queue</p>
                            </div>
                        </div>
                        <span style="font-size: 10px; background: #e0f2fe; color: #0369a1; padding: 3px 10px; border-radius: 20px; font-weight: 700; border: 1px solid #bae6fd;">
                            Active Queue
                        </span>
                    </div>

                    <?php if (count($active_queue_list) > 0): ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 15px;">
                            <?php foreach ($active_queue_list as $q): ?>
                                <div style="background: #f8fafc; border: 1.5px solid var(--border); border-radius: 12px; padding: 15px; display: flex; flex-direction: column; justify-content: space-between; transition: 0.2s; position: relative; border-left: 5px solid <?php echo $q['status'] === 'in-progress' ? 'var(--primary)' : ($q['status'] === 'called' ? 'var(--accent-gold)' : '#cbd5e1'); ?>">
                                    
                                    <!-- Ticket Badge -->
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                                        <span style="background: var(--primary); color: white; padding: 2px 8px; border-radius: 6px; font-size: 10px; font-weight: 800; letter-spacing: 0.5px;">Ticket #<?php echo $q['queue_position']; ?></span>
                                        
                                        <?php if ($q['status'] === 'waiting'): ?>
                                            <span style="background: #fff3cd; color: #856404; border: 1px solid #ffeeba; font-size: 9px; font-weight: 800; text-transform: uppercase; padding: 2px 6px; border-radius: 10px;">⏳ Checked In (Waiting)</span>
                                        <?php elseif ($q['status'] === 'called'): ?>
                                            <span style="background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; font-size: 9px; font-weight: 800; text-transform: uppercase; padding: 2px 6px; border-radius: 10px;">📢 Called to Office</span>
                                        <?php else: ?>
                                            <span style="background: #d1e7dd; color: #15803d; border: 1px solid #badbcc; font-size: 9px; font-weight: 800; text-transform: uppercase; padding: 2px 6px; border-radius: 10px;">🩺 In Consultation</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Patient Info -->
                                    <div style="margin-bottom: 12px;">
                                        <strong style="font-size: 14px; color: var(--primary); display: block;">👤 <?php echo htmlspecialchars($q['first_name'] . ' ' . $q['last_name']); ?></strong>
                                        <small style="color: var(--text-muted); font-weight: 600; display: block; margin-top: 1px;">✉️ <?php echo htmlspecialchars($q['email']); ?></small>
                                        <span style="font-size: 10px; color: #888; font-weight: 500; display: block; margin-top: 5px;"><i class="fa-solid fa-clock"></i> Checked in: <?php echo date('h:i A', strtotime($q['check_in_time'])); ?></span>
                                    </div>

                                    <!-- Real-time Queue Actions Form -->
                                    <div style="border-top: 1px solid #e2e8f0; padding-top: 10px; display: flex; gap: 6px; justify-content: flex-end;">
                                        <?php if ($q['status'] === 'waiting'): ?>
                                            <form method="POST" style="margin: 0; width: 100%;">
                                                <input type="hidden" name="action" value="update_queue_status">
                                                <input type="hidden" name="queue_id" value="<?php echo $q['queue_id']; ?>">
                                                <input type="hidden" name="status" value="called">
                                                <button type="submit" style="width: 100%; background: var(--accent-gold); color: white; border: none; padding: 6px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; transition: 0.2s;"><i class="fa-solid fa-bullhorn"></i> Call Patient</button>
                                            </form>
                                        <?php elseif ($q['status'] === 'called'): ?>
                                            <form method="POST" style="margin: 0; width: 100%;">
                                                <input type="hidden" name="action" value="update_queue_status">
                                                <input type="hidden" name="queue_id" value="<?php echo $q['queue_id']; ?>">
                                                <input type="hidden" name="status" value="in-progress">
                                                <button type="submit" style="width: 100%; background: var(--primary); color: white; border: none; padding: 6px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; transition: 0.2s;"><i class="fa-solid fa-stethoscope"></i> Start Consult</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" style="margin: 0; width: 100%;">
                                                <input type="hidden" name="action" value="update_queue_status">
                                                <input type="hidden" name="queue_id" value="<?php echo $q['queue_id']; ?>">
                                                <input type="hidden" name="status" value="completed">
                                                <button type="submit" style="width: 100%; background: #16a34a; color: white; border: none; padding: 6px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer; transition: 0.2s;"><i class="fa-solid fa-circle-check"></i> Checkout</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center; padding: 25px 15px; background: #f8fafc; border-radius: 12px; border: 1.5px dashed var(--border);">
                            <span style="font-size: 24px; display: block; margin-bottom: 5px;">📋</span>
                            <strong style="color: var(--primary); font-size: 13.5px; display: block;">Clinical Queue is Empty</strong>
                            <small style="color: var(--text-muted); font-weight: 600; margin-top: 2px; display: block; font-size: 0.75rem;">No patients checked in for your consultations today.</small>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 2-COLUMN SIDE-BY-SIDE GRID: OPERATING HOURS & SUPPLY STATUS -->
                <div class="operating-supplies-grid">
                    
                    <!-- 📅 CLINIC OPERATING HOURS -->
                    <div class="card" style="margin: 0; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <div class="card-header" style="padding: 12px 15px; font-size: 0.85rem; font-weight: 700; background: var(--primary); color: white;">📅 CLINIC OPERATING HOURS</div>
                            <div class="schedule-body" style="background: white;">
                                <?php foreach ($schedule_config as $day => $time): ?>
                                    <div class="schedule-row <?php echo ($day == $current_day) ? 'today' : ''; ?>" style="padding: 8px 15px; font-size: 0.8rem; display: flex; justify-content: space-between; border-bottom: 1px solid #f1f5f9;">
                                        <span style="font-weight: 600;"><?php echo $day; ?></span>
                                        <span style="color: #64748b; font-weight: 700;"><?php echo $time ? ($time[0] . ' - ' . $time[1]) : 'Closed'; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        
                        <!-- ⚠️ Low Stock Alerts inside Operating Hours Card to balance heights -->
                        <div style="padding: 12px 15px; background: #fff5f5; border-top: 1px solid #fee2e2;">
                            <p style="font-size: 0.7rem; font-weight: 800; color: #dc2626; text-transform: uppercase; margin-bottom: 5px; display: flex; align-items: center; gap: 4px;">
                                ⚠️ Low Stock Alerts
                            </p>
                            <?php if ($low_stock_count > 0): ?>
                                <div style="display: flex; flex-direction: column; gap: 4px;">
                                    <?php 
                                    $stmtL = $pdo->query("SELECT name as med_name, stock_quantity FROM medicine WHERE stock_quantity <= 10 AND stock_quantity > 0 ORDER BY stock_quantity ASC LIMIT 2");
                                    $low_meds = $stmtL->fetchAll(PDO::FETCH_ASSOC);
                                    foreach ($low_meds as $lm):
                                    ?>
                                        <div style="display: flex; justify-content: space-between; align-items: center; background: white; padding: 4px 8px; border-radius: 6px; border: 1px solid #fecaca; font-size: 0.75rem; font-weight: 600;">
                                            <span style="color: #374151; max-width: 110px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($lm['med_name']); ?>">💊 <?php echo htmlspecialchars($lm['med_name']); ?></span>
                                            <span style="color: #dc2626; font-weight: 800; background: #ffe3e3; padding: 1px 4px; border-radius: 4px; font-size: 0.7rem;"><?= $lm['stock_quantity'] ?> left</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p style="font-size: 0.75rem; font-weight: 700; color: #16a34a; display: flex; align-items: center; gap: 4px;">
                                    ✅ Fully stocked.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- 📦 CLINICAL SUPPLIES STOCK STATUS (COMPACT & SCROLL-CONTAINED) -->
                    <div class="card" style="padding: 20px; margin: 0; display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 15px; border-bottom: 1px solid var(--border); padding-bottom: 10px; flex-wrap: wrap; gap: 10px;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <span style="font-size: 20px;">📦</span>
                                    <div>
                                        <h3 style="color: var(--primary); font-weight: 800; font-size: 1.1rem; margin: 0;">Clinical Supply Stock Status</h3>
                                        <p style="font-size: 0.7rem; color: var(--text-muted); font-weight: 600;">Real-time medical supplies registered in inventory</p>
                                    </div>
                                </div>
                                
                                <div style="display: flex; gap: 10px;">
                                    <div style="text-align: center; background: #f8fafc; padding: 4px 8px; border-radius: 6px; border: 1px solid var(--border);">
                                        <span style="font-size: 0.6rem; font-weight: 800; color: var(--text-muted); text-transform: uppercase;">Total SKUs</span>
                                        <strong style="font-size: 0.9rem; color: var(--primary); font-weight: 800; display: block;"><?= $total_supply_items ?></strong>
                                    </div>
                                    <div style="text-align: center; background: #ffe3e3; padding: 4px 8px; border-radius: 6px; border: 1px solid #ffccd5;">
                                        <span style="font-size: 0.6rem; font-weight: 800; color: #d90429; text-transform: uppercase;">Low Stock</span>
                                        <strong style="font-size: 0.9rem; color: #d90429; font-weight: 800; display: block;"><?= $critical_supply_count ?></strong>
                                    </div>
                                </div>
                            </div>

                            <div style="max-height: 220px; overflow-y: auto; padding-right: 5px;">
                                <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                                    <thead style="position: sticky; top: 0; background: white; z-index: 10;">
                                        <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                                            <th style="padding: 8px 5px; color: var(--text-muted); font-size: 0.7rem; font-weight: 800; text-transform: uppercase;">Supply Item</th>
                                            <th style="padding: 8px 5px; text-align: center; color: var(--text-muted); font-size: 0.7rem; font-weight: 800; text-transform: uppercase;">Stock</th>
                                            <th style="padding: 8px 5px; text-align: center; color: var(--text-muted); font-size: 0.7rem; font-weight: 800; text-transform: uppercase;">Min</th>
                                            <th style="padding: 8px 5px; text-align: right; color: var(--text-muted); font-size: 0.7rem; font-weight: 800; text-transform: uppercase;">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if(!empty($critical_supplies)): ?>
                                            <?php foreach($critical_supplies as $supply): 
                                                $is_crit = $supply['stock_level'] <= $supply['reorder_level'];
                                                $is_empty = $supply['stock_level'] <= 0;
                                            ?>
                                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                                <td style="padding: 10px 5px; font-weight: 700; color: var(--text-main);">
                                                    <?= htmlspecialchars($supply['item_name']) ?>
                                                </td>
                                                <td style="padding: 10px 5px; text-align: center; font-weight: 800; color: <?= $is_empty ? '#d90429' : ($is_crit ? '#b5835a' : '#2d6a4f') ?>">
                                                    <?= $supply['stock_level'] ?>
                                                </td>
                                                <td style="padding: 10px 5px; text-align: center; color: var(--text-muted); font-weight: 600;">
                                                    <?= $supply['reorder_level'] ?>
                                                </td>
                                                <td style="padding: 10px 5px; text-align: right;">
                                                    <?php if($is_empty): ?>
                                                        <span style="font-size: 0.65rem; font-weight: 800; background: #ffe3e3; color: #d90429; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">Stockout</span>
                                                    <?php elseif($is_crit): ?>
                                                        <span style="font-size: 0.65rem; font-weight: 800; background: #fff9db; color: #b5835a; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">Low</span>
                                                    <?php else: ?>
                                                        <span style="font-size: 0.65rem; font-weight: 800; background: #e8f5e9; color: #2d6a4f; padding: 2px 6px; border-radius: 4px; text-transform: uppercase;">OK</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="4" style="padding: 15px; text-align: center; color: var(--text-muted); font-size: 0.8rem;">No supplies registered.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- RIGHT COLUMN: CLINIC ACTIONS & APPOINTMENTS HUB -->
            <div class="right-column" style="display: flex; flex-direction: column; gap: 1.5rem;">
                
                <!-- ⚡ QUICK ACTION CONTROL PANEL (TOP OF RIGHT COL FOR DOCTOR ACCESSIBILITY) -->
                <div class="card" style="padding: 20px;">
                    <div style="font-weight: 800; font-size: 0.8rem; letter-spacing: 0.5px; color: var(--primary); text-transform: uppercase; margin-bottom: 15px; border-bottom: 1px solid var(--border); padding-bottom: 8px;">
                        ⚡ Clinical Suite Actions
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">
                        <a href="doctor-vital-signs.php" class="action-link">
                            <span style="font-size: 16px; display: block; margin-bottom: 4px;">🌡️</span> Vital Signs
                        </a>
                        <a href="doctor-patient-list.php" class="action-link">
                            <span style="font-size: 16px; display: block; margin-bottom: 4px;">👥</span> Patient List
                        </a>
                        <a href="doctor-medical-records.php" class="action-link">
                            <span style="font-size: 16px; display: block; margin-bottom: 4px;">📋</span> Med Records
                        </a>
                        <a href="doctor-prescriptions.php" class="action-link">
                            <span style="font-size: 16px; display: block; margin-bottom: 4px;">💊</span> Prescriptions
                        </a>
                        <a href="doctor-lab-orders.php" class="action-link">
                            <span style="font-size: 16px; display: block; margin-bottom: 4px;">🧪</span> Lab Orders
                        </a>
                        <a href="doctor-availability.php" class="action-link">
                            <span style="font-size: 16px; display: block; margin-bottom: 4px;">📅</span> My Schedule
                        </a>
                    </div>
                </div>

                <!-- 📅 APPOINTMENTS HUB -->
                <a href="doctor-appointment.php" class="card stats-hub" style="padding: 20px; margin: 0; text-decoration: none; display: block; transition: transform 0.3s ease, box-shadow 0.3s ease; cursor: pointer;">
                    <div class="hub-icon" style="width: 45px; height: 45px; font-size: 1.1rem; margin-bottom: 10px; background: var(--purple-main); display: flex; align-items: center; justify-content: center; border-radius: 12px; color: white;">📅</div>
                    <h3 style="color: var(--primary); font-weight: 800; font-size: 0.95rem; letter-spacing: 0.5px; margin-bottom: 5px; text-decoration: none;">APPOINTMENTS</h3>
                    <div class="hub-total" style="font-size: 2.5rem; color: var(--purple-main); font-weight: 800; line-height: 1.2;"><?php echo $total_appointments; ?></div>
                    <p class="hub-label" style="font-size: 0.75rem; margin-bottom: 15px; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">TOTAL TODAY</p>

                    <div class="hub-grid" style="padding-top: 10px; width: 100%; display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div class="hub-item" style="background: rgba(37, 99, 235, 0.05); padding: 10px; border-radius: 12px; display: flex; flex-direction: column; align-items: center; border: 1px solid rgba(37, 99, 235, 0.1);">
                            <span class="hub-sub" style="font-size: 0.65rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Pending</span>
                            <span class="hub-val" style="font-size: 1.25rem; color: #2563eb; font-weight: 800;"><?php echo $waiting_count; ?></span>
                        </div>
                        <div class="hub-item" style="background: rgba(22, 163, 74, 0.05); padding: 10px; border-radius: 12px; display: flex; flex-direction: column; align-items: center; border: 1px solid rgba(22, 163, 74, 0.1);">
                            <span class="hub-sub" style="font-size: 0.65rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Completed</span>
                            <span class="hub-val" style="font-size: 1.25rem; color: #16a34a; font-weight: 800;"><?php echo $completed_count; ?></span>
                        </div>
                    </div>
                    
                    <div style="margin-top: 15px; width: 100%;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                            <p style="font-size: 0.7rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Completion Rate</p>
                            <span style="font-size: 0.7rem; background: #e0f2fe; color: #0369a1; padding: 1px 6px; border-radius: 8px; font-weight: 700;"><?php echo $appointment_completion_rate; ?>%</span>
                        </div>
                        <div style="width: 100%; height: 8px; background: #eee; border-radius: 10px; overflow: hidden;">
                            <div style="width: <?php echo $appointment_completion_rate; ?>%; height: 100%; background: linear-gradient(90deg, var(--purple-main), #a855f7);"></div>
                        </div>
                    </div>

                    <div style="text-align: center; margin-top: 15px; font-size: 0.75rem; font-weight: 700; color: var(--purple-main); display: flex; align-items: center; justify-content: center; gap: 6px;">
                        Manage Appointments & Update Status ➔
                    </div>
                </a>

            </div>
            
        </div>
    </div>

    <script>
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
                                    <div style="font-weight: 700; font-size: 0.8rem; color: var(--header-green); margin-bottom: 2px;">
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