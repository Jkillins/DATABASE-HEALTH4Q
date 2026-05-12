<?php
/**
 * assistant-dashboard.php
 * Enhanced UI version with Forest Green theme (Matches Doctor UI)
 */
require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Access Control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'clinical_assistant') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
$user_id = $_SESSION['user_id'];

try {
    // 1. Fetch Assistant Profile & Clinic Info
    $stmt = $pdo->prepare("
        SELECT u.first_name, u.last_name, ca.assistant_id, ca.clinic 
        FROM users u 
        JOIN clinical_assistant ca ON u.user_id = ca.user_id 
        WHERE u.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $worker = $stmt->fetch();
    $assistant_id = $worker['assistant_id'];

    // 2. Daily Stats
    $today = date('Y-m-d');
    
    // Pending Appointments
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointment WHERE status='scheduled' AND DATE(schedule_start) = ?");
    $stmt->execute([$today]);
    $pending_count = $stmt->fetchColumn();

    // Completed Appointments
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointment WHERE status='completed' AND DATE(schedule_start) = ?");
    $stmt->execute([$today]);
    $completed_count = $stmt->fetchColumn();

    // Referrals issued today
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM referral WHERE DATE(issued_at) = ?");
    $stmt->execute([$today]);
    $referral_count = $stmt->fetchColumn();

    // 3. Live Appointment Queue for the specific clinic
    $recent_appts = [];
    if (!empty($worker['clinic'])) {
        $stmt = $pdo->prepare("
            SELECT a.*, u_p.first_name as pf, u_p.last_name as pl, u_d.last_name as dl, vt.name as visit_type
            FROM appointment a
            JOIN patient p ON a.patient_id = p.patient_id
            JOIN users u_p ON p.user_id = u_p.user_id
            JOIN doctor d ON a.doctor_id = d.doctor_id
            JOIN users u_d ON d.user_id = u_d.user_id
            JOIN visit_type vt ON a.visit_type_id = vt.visit_type_id
            WHERE d.clinic = ? AND DATE(a.schedule_start) = ?
            ORDER BY a.schedule_start ASC LIMIT 5
        ");
        $stmt->execute([$worker['clinic'], $today]);
        $recent_appts = $stmt->fetchAll();
    }
} catch (Exception $e) { $error = $e->getMessage(); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assistant Center | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-green: #1a4d34;
            --light-bg: #c5e6e1;
            --white: #ffffff;
            --accent-green: #2d6a4f;
            --text-dark: #1b4332;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Quicksand', sans-serif; }

        body {
            background: radial-gradient(circle at center, #d8f3dc 0%, var(--light-bg) 100%);
            min-height: 100vh;
            color: var(--text-dark);
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
            margin-right: 10px;
        }
        .nav-links a.active { background: var(--accent-green); }

        .logout-btn { background: #d90429; color: white; padding: 8px 18px; border-radius: 5px; text-decoration: none; font-weight: bold; font-size: 12px; }

        /* --- CONTENT --- */
        .container { max-width: 1100px; margin: 30px auto; padding: 0 20px; }

        .welcome-card {
            background: var(--white);
            padding: 35px;
            border-radius: 20px;
            margin-bottom: 30px;
            border-bottom: 6px solid #84ccb1;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
        }

        .welcome-card h1 { font-size: 2rem; color: var(--accent-green); font-weight: 800; }
        
        .badge {
            display: inline-block;
            background: #e9f5f2;
            padding: 6px 16px;
            border-radius: 12px;
            color: #4361ee;
            font-size: 12px;
            font-weight: 600;
            margin-top: 15px;
            border: 1px solid #d1e9e3;
        }

        /* --- GRID --- */
        .main-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 25px;
            margin-bottom: 30px;
        }

        .card {
            background: var(--white);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.04);
            text-align: center;
        }

        .card h3 { font-size: 16px; margin-bottom: 20px; color: #666; text-transform: uppercase; letter-spacing: 1px; }

        .stat-big { font-size: 54px; font-weight: 800; color: var(--primary-green); margin-bottom: 10px; }

        /* --- TABLE --- */
        .table-card {
            background: var(--white);
            border-radius: 20px;
            padding: 30px;
            grid-column: span 2;
            box-shadow: 0 10px 25px rgba(0,0,0,0.04);
        }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px; font-size: 11px; color: #999; border-bottom: 2px solid #f8fafc; }
        td { padding: 14px 12px; font-size: 13px; border-bottom: 1px solid #f1f5f9; }

        .status-pill {
            padding: 4px 10px; border-radius: 6px; font-size: 10px; font-weight: 800; text-transform: uppercase;
        }
        .status-scheduled { background: #fff7ed; color: #c2410c; }
        .status-completed { background: #f0fdf4; color: #15803d; }

        /* --- ACTION BAR --- */
        .action-bar {
            background: var(--primary-green);
            padding: 25px;
            border-radius: 20px;
            color: white;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .action-btn {
            background: rgba(255,255,255,0.15);
            color: white;
            padding: 12px;
            border-radius: 10px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            text-align: center;
            transition: 0.3s;
        }
        .action-btn:hover { background: white; color: var(--primary-green); }
    </style>
</head>
<body>

    <nav class="top-nav">
        <div class="nav-brand">
          <img src="images/Logo_only.png" alt="Health4Q">
        </div>
        <div class="nav-links">
            <a href="assistant-dashboard.php" class="active">📊 Overview</a>
            <a href="manage-appointments.php">📅 Live Queue</a>
            <a href="patient-records.php">📁 Medical Vault</a>
        </div>
        <a href="logout.php" class="logout-btn">Exit System</a>
    </nav>

    <div class="container">
        <div class="welcome-card">
            <p style="color: #888; font-weight: 600; font-size: 14px;">Clinic Operations</p>
            <h1>Hello, <?php echo htmlspecialchars($worker['first_name']); ?>!</h1>
            <p>Monitoring activity for <strong><?php echo htmlspecialchars($worker['clinic'] ?? 'Unassigned Clinic'); ?></strong></p>
            <div class="badge">📅 <?php echo date('l, F d, Y'); ?></div>
        </div>

        <div class="main-grid">
            <div class="card">
                <h3>Awaiting</h3>
                <div class="stat-big"><?php echo $pending_count; ?></div>
                <p style="font-size: 12px; color: #888;">Patients in queue</p>
            </div>
            <div class="card">
                <h3>Cleared</h3>
                <div class="stat-big" style="color: #2d6a4f;"><?php echo $completed_count; ?></div>
                <p style="font-size: 12px; color: #888;">Completed visits today</p>
            </div>
            <div class="card">
                <h3>Referrals</h3>
                <div class="stat-big" style="color: #4361ee;"><?php echo $referral_count; ?></div>
                <p style="font-size: 12px; color: #888;">Processed today</p>
            </div>
        </div>

        <div class="main-grid">
            <div class="table-card">
                <div style="display:flex; justify-content: space-between; margin-bottom: 20px;">
                    <h3 style="color: var(--text-dark); margin:0;">Live tracker</h3>
                    <a href="manage-appointments.php" style="font-size: 12px; color: var(--accent-green); font-weight: 700; text-decoration:none;">View Full Queue →</a>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Doctor</th>
                            <th>Time</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_appts)): ?>
                            <tr><td colspan="4" style="text-align:center; padding: 30px; color: #aaa;">No active appointments.</td></tr>
                        <?php else: ?>
                            <?php foreach($recent_appts as $a): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($a['pf'].' '.$a['pl']); ?></strong><br><small><?php echo $a['visit_type']; ?></small></td>
                                <td>Dr. <?php echo htmlspecialchars($a['dl']); ?></td>
                                <td><?php echo date('h:i A', strtotime($a['schedule_start'])); ?></td>
                                <td>
                                    <span class="status-pill status-<?php echo $a['status']; ?>">
                                        <?php echo $a['status']; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="action-bar">
                <h3 style="color: white; margin-bottom: 10px;">Quick Tasks</h3>
                <a href="referral-forms.php" class="action-btn">📄 Draft New Referral</a>
                <a href="patient-records.php" class="action-btn">🔍 Search Patient History</a>
                <a href="notifications.php" class="action-btn">🔔 Broadcast Alert</a>
                
                <div style="margin-top: 20px; padding: 15px; background: rgba(0,0,0,0.1); border-radius: 12px; font-size: 12px;">
                    <p style="opacity: 0.8;"><strong>Clinic Reminder:</strong></p>
                    <p>Ensure all medical records are synced before daily logout at 5:00 PM.</p>
                </div>
            </div>
        </div>
    </div>

</body>
</html>