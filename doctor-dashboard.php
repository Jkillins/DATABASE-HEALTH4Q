<?php
/**
 * doctor-dashboard.php
 * Fixed TypeError for number_format
 */

require_once 'config.php';
requireRole(ROLE_DOCTOR); 

$pdo = getPDO();
$doctor_id = getCurrentRoleId();
$doctor = getDoctorProfile($pdo, $doctor_id);

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
    // Attempt to get detailed stats
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
    // Ensure total_units is at least 0 so number_format doesn't complain
    $total_units = $inv['total_units'] !== null ? $inv['total_units'] : 0;
    $availability_rate = ($medicine_count > 0) ? round(($inv['in_stock'] / $medicine_count) * 100) : 0;
    $low_stock_count = $inv['low_stock'] ?: 0;

} catch (PDOException $e) {
    // Fallback if stock_quantity column doesn't exist
    $stmtFallback = $pdo->query("SELECT COUNT(*) FROM medicine");
    $medicine_count = $stmtFallback->fetchColumn();
    $total_units = "N/A"; // This triggered your error
    $availability_rate = 0;
    $low_stock_count = 0;
}
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
        body { background-color: var(--bg-soft); color: var(--text-main); line-height: 1.6; }

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
        .dashboard-grid { display: grid; grid-template-columns: 320px 1fr; gap: 2rem; }
        .card { background: var(--white); border-radius: 20px; border: 1px solid var(--border); overflow: hidden; box-shadow: var(--shadow); }

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
            background: var(--white); padding: 1.5rem 1rem; text-align: center; text-decoration: none;
            border-radius: 16px; border: 1px solid var(--border); color: var(--primary);
            font-weight: 700; font-size: 0.8rem; transition: all 0.3s ease;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .action-link:hover { background: var(--primary); color: white; transform: translateY(-5px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); }

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
            <a href="doctor-prescriptions.php">💊 Medicine</a>
            <a href="doctor-profile.php">⚙️ Profile</a>
        </div>
<a href="logout.php" style="background-color: #e74c3c; color: white; font-size: 0.8rem; font-weight: 700; text-decoration: none; padding: 8px 16px; border-radius: 8px; transition: 0.3s;">Log Out</a>    </nav>

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

        <div class="dashboard-grid">
            <aside class="card">
                <div class="card-header">CLINIC OPERATING HOURS</div>
                <div class="schedule-body">
                    <?php foreach ($schedule_config as $day => $time): ?>
                        <div class="schedule-row <?php echo ($day == $current_day) ? 'today' : ''; ?>">
                            <span><?php echo $day; ?></span>
                            <span><?php echo $time ? ($time[0] . ' - ' . $time[1]) : 'Closed'; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div style="padding: 1.5rem; background: #f8fafc; border-top: 1px solid var(--border);">
                    <p style="font-size: 0.7rem; font-weight: 800; color: #c53030; text-transform: uppercase; margin-bottom: 5px;">Critical Alerts</p>
                    <p style="font-size: 0.9rem; font-weight: 700; color: #742a2a;">
                        <?php echo $low_stock_count; ?> items require restock.
                    </p>
                </div>
            </aside>

            <!-- MAIN MEDICINE HUB -->
            <main class="card stats-hub">
                <div class="hub-icon">💊</div>
                <h3 style="color: var(--primary); font-weight: 800; letter-spacing: 1px;">CLINIC INVENTORY</h3>
                <div class="hub-total"><?php echo $medicine_count; ?></div>
                <p class="hub-label">Total Unique SKUs</p>

                <div class="hub-grid">
                    <div class="hub-item">
                        <span class="hub-sub">In-Stock Units</span>
                        <!-- FIXED LINE BELOW: Checks if number before formatting -->
                        <span class="hub-val"><?php echo is_numeric($total_units) ? number_format($total_units) : $total_units; ?></span>
                    </div>
                    <div class="hub-item">
                        <span class="hub-sub">Stock Health</span>
                        <span class="hub-val" style="color: #22c55e;">Synced</span>
                    </div>
                </div>
                
                <div style="margin-top: 2.5rem; width: 100%; padding: 0 2rem;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <p style="font-size: 0.8rem; color: var(--text-muted); font-weight: 600;">Availability Rate</p>
                        <span style="font-size: 0.75rem; background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 10px; font-weight: 700;"><?php echo $availability_rate; ?>%</span>
                    </div>
                    <div style="width: 100%; height: 12px; background: #eee; border-radius: 10px; overflow: hidden;">
                        <div style="width: <?php echo $availability_rate; ?>%; height: 100%; background: linear-gradient(90deg, var(--primary), var(--primary-light)); transition: width 1s ease;"></div>
                    </div>
                    <p style="font-size: 0.65rem; color: var(--text-muted); text-align: left; margin-top: 8px;">
                        This metrics tracks the percentage of catalog items currently available for prescription.
                    </p>
                </div>
            </main>
        </div>

        <div class="actions-grid">
            <a href="doctor-vital-signs.php" class="action-link" style="border-top: 4px solid var(--vitals);">
                <span>🌡️</span> Vital Signs
            </a>
            <a href="doctor-patient-list.php" class="action-link">
                <span>👥</span> Patient List
            </a>
            <a href="doctor-medical-records.php" class="action-link">
                <span>📋</span> Medical Records
            </a>
            <a href="doctor-prescriptions.php" class="action-link">
                <span>💊</span> Prescriptions
            </a>
            <a href="doctor-lab-orders.php" class="action-link">
                <span>🧪</span> Lab Orders
            </a>
            <a href="doctor-availability.php" class="action-link">
                <span>📅</span> My Schedule
            </a>
        </div>
    </div>

</body>
</html>