<?php
/**
 * patient-dashboard.php - Full Premium Forest Green Patient Dashboard with Live Queue Tracker
 */
require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. Access Control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
$user_id = $_SESSION['user_id'];

try {
    // 2. Fetch Patient Info
    $stmt = $pdo->prepare('SELECT u.*, p.* FROM users u JOIN patient p ON u.user_id = p.user_id WHERE u.user_id = ?');
    $stmt->execute([$user_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        die("Patient profile not found. Please contact administration.");
    }
    $patient_id = $patient['patient_id'];

    // 3. Fetch Active Queue Status for Today
    $stmtQ = $pdo->prepare("
        SELECT pq.*, u_d.last_name as doctor_lname, 
               (SELECT COUNT(*) FROM patient_queue WHERE DATE(check_in_time) = CURDATE() AND queue_position < pq.queue_position AND status IN ('waiting', 'called')) as patients_ahead
        FROM patient_queue pq
        JOIN doctor d ON pq.doctor_id = d.doctor_id
        JOIN users u_d ON d.user_id = u_d.user_id
        WHERE pq.patient_id = ? AND DATE(pq.check_in_time) = CURDATE() AND pq.status IN ('waiting', 'called', 'in-progress')
        ORDER BY pq.queue_id DESC
        LIMIT 1
    ");
    $stmtQ->execute([$patient_id]);
    $active_queue = $stmtQ->fetch(PDO::FETCH_ASSOC);

    // 4. Fetch Appointments
    $stmt = $pdo->prepare(
        "SELECT a.*, u.first_name as doc_fname, u.last_name as doc_lname, d.specialty 
         FROM appointment a 
         JOIN doctor d ON a.doctor_id = d.doctor_id
         JOIN users u ON d.user_id = u.user_id 
         WHERE a.patient_id = ? 
         ORDER BY a.schedule_start ASC"
    );
    $stmt->execute([$patient_id]);
    $all_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $next_appt = null;
    foreach($all_appointments as $appt) {
        if ($appt['status'] === 'scheduled' && strtotime($appt['schedule_start']) > time()) {
            $next_appt = $appt;
            break;
        }
    }

    // 5. Fetch Active Prescriptions
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM prescription p
        JOIN medical_record mr ON p.record_id = mr.record_id
        WHERE mr.patient_id = ? AND p.status = 'active'
    ");
    $stmt->execute([$patient_id]);
    $active_prescriptions = $stmt->fetchColumn() ?: 0;

    // 6. Fetch Allergies
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM patient_allergy WHERE patient_id = ?");
    $stmt->execute([$patient_id]);
    $allergy_count = $stmt->fetchColumn() ?: 0;

    // 7. Fetch Latest Vital Signs
    $stmt = $pdo->prepare("
        SELECT vs.* FROM vital_signs vs
        JOIN medical_record mr ON vs.medical_record_id = mr.record_id
        WHERE mr.patient_id = ?
        ORDER BY vs.recorded_at DESC
        LIMIT 1
    ");
    $stmt->execute([$patient_id]);
    $latest_vitals = $stmt->fetch(PDO::FETCH_ASSOC);

    // 8. Fetch Lab Tests Count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM test_order t_order
        JOIN medical_record mr ON t_order.record_id = mr.record_id
        WHERE mr.patient_id = ? AND t_order.status = 'completed'
    ");
    $stmt->execute([$patient_id]);
    $completed_tests = $stmt->fetchColumn() ?: 0;

    // 9. Fetch Active Broadcast Messages (Announcements)
    $stmtB = $pdo->prepare("
        SELECT title, message, priority, clinic_name, created_at 
        FROM broadcast_message 
        WHERE expires_at > NOW() 
        ORDER BY created_at DESC 
        LIMIT 3
    ");
    $stmtB->execute();
    $broadcasts = $stmtB->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) { 
    $all_appointments = [];
    $active_prescriptions = 0;
    $allergy_count = 0;
    $latest_vitals = null;
    $completed_tests = 0;
    $broadcasts = [];
    $active_queue = null;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Dashboard | Health4Q</title>
    <link rel="icon" type="image/png" href="images/Logo_only.png">
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
            background: url('images/Background_color.png') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            color: var(--text-dark);
            display: flex;
            flex-direction: column;
            position: relative;
            overflow-x: hidden;
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
        }
        .nav-links a:hover, .nav-links a.active { background: var(--accent-green); }

        .logout-btn {
            background: #d90429;
            color: white;
            padding: 8px 18px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            font-size: 12px;
        }

        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; flex: 1; }

        .welcome-card {
            background: var(--white);
            padding: 30px;
            border-radius: 20px;
            margin-bottom: 30px;
            border-left: 8px solid var(--accent-green);
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
        }

        .welcome-card h1 { font-size: 1.8rem; color: var(--accent-green); font-weight: 800; margin-bottom: 5px; }
        
        .date-badge {
            display: inline-block;
            background: #e9f5f2;
            padding: 6px 16px;
            border-radius: 12px;
            color: var(--accent-green);
            font-size: 12px;
            font-weight: 600;
            margin-top: 10px;
            border: 1px solid #d1e9e3;
        }

        /* Live Queue Tracker Premium Widget */
        .queue-tracker-card {
            background: white;
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            border-left: 8px solid #4361ee;
            position: relative;
            overflow: hidden;
        }
        
        .queue-tracker-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #eef2ff;
            padding-bottom: 15px;
            margin-bottom: 20px;
            color: #4361ee;
        }

        .live-pulse {
            width: 10px;
            height: 10px;
            background-color: #4361ee;
            border-radius: 50%;
            display: inline-block;
            animation: pulse-blue 1.5s infinite;
        }

        @keyframes pulse-blue {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(67, 97, 238, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(67, 97, 238, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(67, 97, 238, 0); }
        }

        .queue-number-badge {
            background: #eef2ff;
            color: #4361ee;
            font-size: 13px;
            font-weight: 800;
            padding: 6px 16px;
            border-radius: 12px;
            border: 1px solid #c7d2fe;
        }

        .queue-tracker-body {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .queue-main-info {
            flex: 1;
            min-width: 250px;
        }

        .queue-status-big {
            font-size: 20px;
            font-weight: 800;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .q-status-text.waiting { color: #d97706; }
        .q-status-text.called { color: #ef4444; animation: blinker 1.2s linear infinite; }
        .q-status-text.active { color: #16a34a; }

        @keyframes blinker {
            50% { opacity: 0.4; }
        }

        .queue-doctor-info {
            font-size: 14px;
            color: #555;
        }

        .queue-eta-section {
            display: flex;
            align-items: center;
            gap: 20px;
            background: #f8fafc;
            padding: 15px 25px;
            border-radius: 15px;
            border: 1px solid #e2e8f0;
            flex-wrap: wrap;
        }

        .queue-stat-pill {
            display: flex;
            flex-direction: column;
            align-items: center;
            background: #4361ee;
            color: white;
            padding: 10px 20px;
            border-radius: 12px;
            min-width: 100px;
        }

        .q-stat-val {
            font-size: 24px;
            font-weight: 800;
        }

        .q-stat-lbl {
            font-size: 9px;
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: 0.5px;
            opacity: 0.9;
        }

        .queue-msg {
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            max-width: 320px;
        }

        .urgent-call {
            color: #dc2626;
            font-size: 14px;
        }

        /* --- GRID SYSTEM --- */
        .main-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr); 
            grid-template-rows: auto auto;         
            gap: 25px;
            margin-bottom: 30px;
        }

        .card {
            background: var(--white);
            border-radius: 20px;
            padding: 25px;
            min-height: 280px; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.04);
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.3s ease;
        }

        .card:hover { transform: translateY(-5px); }

        .card-icon {
            width: 50px; height: 50px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 15px; color: white; font-size: 24px;
        }

        .stat-big { font-size: 38px; font-weight: 800; color: var(--accent-green); margin: 5px 0; }
        .stat-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 1.2px; font-weight: 700; }

        .card-link {
            display: block;
            margin-top: 15px;
            padding: 10px;
            background: #f8f9fa;
            color: var(--primary-green);
            text-decoration: none;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            transition: 0.3s;
            border: 1px solid #eee;
        }

        .card-link:hover { background: var(--primary-green); color: white; }

        .list-table { width: 100%; border-collapse: collapse; font-size: 11px; text-align: left; margin-top: 10px; }
        .list-table td { padding: 10px 5px; border-bottom: 1px solid #f5f5f5; }

        .status-pill { padding: 3px 8px; border-radius: 20px; font-size: 9px; font-weight: 700; }
        .status-scheduled { background: #e0f2fe; color: #0369a1; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-canceled { background: #fee2e2; color: #b91c1c; }

        .quick-actions {
            background: var(--white);
            padding: 25px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.03);
            flex-wrap: wrap;
        }
        
        .action-btn {
            flex: 1; min-width: 160px; padding: 14px;
            background: var(--primary-green); color: white;
            text-decoration: none; border-radius: 12px;
            font-size: 12px; font-weight: 600; text-align: center;
            transition: 0.3s;
        }
        .action-btn:hover { background: var(--accent-green); opacity: 0.9; }

        /* Responsive Breakpoints */
        @media (max-width: 992px) { .main-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 650px) { .main-grid { grid-template-columns: 1fr; } }

        /* Clinical Announcements Widget Styles */
        .announcements-container {
            background: var(--white);
            border-radius: 20px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            border: 1px solid rgba(255,255,255,0.4);
            position: relative;
            overflow: hidden;
        }
        .announcements-header {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 800;
            color: var(--accent-green);
            letter-spacing: 1.2px;
            text-transform: uppercase;
            margin-bottom: 20px;
            border-bottom: 2px solid #e9f5f2;
            padding-bottom: 10px;
        }
        .pulse-dot {
            width: 8px;
            height: 8px;
            background-color: #ef4444;
            border-radius: 50%;
            display: inline-block;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(239, 68, 68, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }
        .announcements-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .announcement-item {
            padding: 15px 20px;
            border-radius: 12px;
            border-left: 5px solid var(--accent-green);
            background: #f8f9fa;
            transition: 0.3s;
            text-align: left;
        }
        .announcement-item:hover {
            transform: translateX(5px);
            background: #f1f3f4;
        }
        .announcement-item.priority-urgent {
            border-left-color: #ef4444;
            background: #fff5f5;
        }
        .announcement-item.priority-urgent:hover {
            background: #ffebeb;
        }
        .announcement-meta {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }
        .clinic-badge {
            background: #e8f5e9;
            color: var(--accent-green);
            font-size: 10px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 6px;
            text-transform: uppercase;
        }
        .announcement-item.priority-urgent .clinic-badge {
            background: #ffe3e3;
            color: #ef4444;
        }
        .time-badge {
            font-size: 11px;
            color: #888;
            font-weight: 600;
        }
        .urgent-badge {
            background: #ef4444;
            color: white;
            font-size: 9px;
            font-weight: 800;
            padding: 2px 6px;
            border-radius: 4px;
        }
        .announcement-title {
            font-size: 1.05rem;
            color: var(--text-dark);
            font-weight: 700;
            margin-bottom: 6px;
            text-align: left;
        }
        .announcement-body {
            font-size: 13px;
            color: #555;
            line-height: 1.5;
            font-weight: 500;
            text-align: left;
        }
    </style>
</head>
<body>

    <nav class="top-nav">
        <div class="nav-brand"><img src="images/Logo_only.png" alt="Health4Q"></div>
        <div class="nav-links">
            <a href="patient-dashboard.php" class="active">🏠 Dashboard</a>
            <a href="patientprofile.php">👤 Profile</a>
            <a href="patientappoint.php">📅 Appointments</a>
            <a href="patient-prescriptions.php">💊 Prescriptions</a>
            <a href="patient-lab-results.php">🧪 Lab Results</a>
            <a href="patientmedhist.php">📜 History</a>
        </div>
        <div style="display: flex; align-items: center; gap: 15px;">
            <!-- Real-Time Notification Panel -->
            <div class="notification-container" style="position: relative; display: inline-block;">
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
            <a href="logout.php" class="logout-btn">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="welcome-card">
            <h1>Welcome Back, <?php echo htmlspecialchars($patient['first_name']); ?>! 👋</h1>
            <?php if ($next_appt): ?>
                <p>Your next appointment is with <strong>Dr. <?php echo htmlspecialchars($next_appt['doc_lname']); ?></strong> on <?php echo date('M d, Y', strtotime($next_appt['schedule_start'])); ?>.</p>
            <?php else: ?>
                <p>You have no upcoming appointments. Stay healthy!</p>
            <?php endif; ?>
            <div class="date-badge">📅 <?php echo date('l, F d, Y'); ?></div>
        </div>

        <!-- LIVE QUEUE TRACKER WIDGET -->
        <?php if ($active_queue): ?>
            <div class="queue-tracker-card">
                <div class="queue-tracker-header">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <span class="live-pulse"></span>
                        <span style="font-weight: 800; font-size: 13px; letter-spacing: 1.2px; text-transform: uppercase;">📋 LIVE CLINIC QUEUE</span>
                    </div>
                    <span class="queue-number-badge">Ticket #<?php echo $active_queue['queue_position']; ?></span>
                </div>
                <div class="queue-tracker-body">
                    <div class="queue-main-info">
                        <div class="queue-status-big">
                            <?php if ($active_queue['status'] === 'waiting'): ?>
                                <span class="q-icon">⏳</span> Status: <span class="q-status-text waiting">Checked In (Waiting)</span>
                            <?php elseif ($active_queue['status'] === 'called'): ?>
                                <span class="q-icon">📢</span> Status: <span class="q-status-text called">Proceed to Doctor</span>
                            <?php else: ?>
                                <span class="q-icon">🔄</span> Status: <span class="q-status-text active">In Consultation</span>
                            <?php endif; ?>
                        </div>
                        <div class="queue-doctor-info">
                            Assigned Physician: <strong>Dr. <?php echo htmlspecialchars($active_queue['doctor_lname']); ?></strong>
                        </div>
                    </div>
                    
                    <div class="queue-eta-section">
                        <?php if ($active_queue['status'] === 'waiting'): ?>
                            <div class="queue-stat-pill">
                                <span class="q-stat-val"><?php echo $active_queue['patients_ahead']; ?></span>
                                <span class="q-stat-lbl">Patients Ahead</span>
                            </div>
                            <div class="queue-msg">
                                Please wait in the lobby. We will alert you immediately when the doctor is ready to see you.
                            </div>
                        <?php elseif ($active_queue['status'] === 'called'): ?>
                            <div class="queue-msg urgent-call">
                                🔔 <strong>Your turn has arrived!</strong> Please enter Dr. <?php echo htmlspecialchars($active_queue['doctor_lname']); ?>'s clinic room now.
                            </div>
                        <?php else: ?>
                            <div class="queue-msg">
                                👨‍⚕️ You are currently in active clinical consultation.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- CLINICAL ANNOUNCEMENTS SECTION -->
        <?php if (!empty($broadcasts)): ?>
            <div class="announcements-container">
                <div class="announcements-header">
                    <span>📢 CLINICAL ANNOUNCEMENTS</span>
                    <span class="pulse-dot"></span>
                </div>
                <div class="announcements-list">
                    <?php foreach ($broadcasts as $b): ?>
                        <div class="announcement-item <?= $b['priority'] === 'urgent' ? 'priority-urgent' : 'priority-normal' ?>">
                            <div class="announcement-meta">
                                <span class="clinic-badge"><?= htmlspecialchars($b['clinic_name'] ?? 'General Clinic') ?></span>
                                <span class="time-badge">🕒 <?= date('F d, Y \a\t h:i A', strtotime($b['created_at'])) ?></span>
                                <?php if ($b['priority'] === 'urgent'): ?>
                                    <span class="urgent-badge">⚠️ URGENT</span>
                                <?php endif; ?>
                            </div>
                            <h4 class="announcement-title"><?= htmlspecialchars($b['title']) ?></h4>
                            <p class="announcement-body"><?= nl2br(htmlspecialchars($b['message'])) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="main-grid">
            <!-- Row 1, Col 1: Health Profile -->
            <div class="card">
                <div>
                    <div class="card-icon" style="background: #ef4444;">❤️</div>
                    <h3>Health Profile</h3>
                </div>
                <div>
                    <p class="stat-label">Blood Type</p>
                    <div class="stat-big" style="color: #ef4444;"><?php echo htmlspecialchars($patient['blood_type'] ?? '--'); ?></div>
                    <p class="stat-label">Age</p>
                    <p style="font-weight: 700;">
                        <?php 
                            if (!empty($patient['date_of_birth'])) {
                                $dob = new DateTime($patient['date_of_birth']);
                                echo $dob->diff(new DateTime())->y . ' years';
                            } else { echo 'Not Set'; }
                        ?>
                    </p>
                </div>
                <a href="patientprofile.php" class="card-link">Update Profile →</a>
            </div>

            <!-- Row 1, Col 2: Prescriptions -->
            <div class="card">
                <div>
                    <div class="card-icon" style="background: #84cc16;">💊</div>
                    <h3>Prescriptions</h3>
                </div>
                <div class="stat-big" style="color: #84cc16;"><?php echo $active_prescriptions; ?></div>
                <p class="stat-label">Active Medications</p>
                <a href="patient-prescriptions.php" class="card-link">View All →</a>
            </div>

            <!-- Row 1, Col 3: Appointments -->
            <div class="card">
                <div>
                    <div class="card-icon" style="background: #4361ee;">📅</div>
                    <h3>Appointments</h3>
                </div>
                <table class="list-table">
                    <?php if (!empty($all_appointments)): ?>
                        <?php foreach (array_slice($all_appointments, 0, 2) as $appt): ?>
                        <tr>
                            <td>
                                <strong>Dr. <?php echo htmlspecialchars($appt['doc_lname']); ?></strong><br>
                                <small><?php echo date('M d', strtotime($appt['schedule_start'])); ?></small>
                            </td>
                            <td style="text-align: right;">
                                <span class="status-pill status-<?php echo strtolower($appt['status']); ?>">
                                    <?php echo strtoupper(substr($appt['status'], 0, 3)); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td style="text-align: center; color: #aaa; padding: 20px 0;">No upcoming visits</td></tr>
                    <?php endif; ?>
                </table>
                <a href="patientappoint.php" class="card-link">Book Now →</a>
            </div>

            <!-- Row 2, Col 1: Vital Signs -->
            <div class="card">
                <div>
                    <div class="card-icon" style="background: #f43f5e;">💓</div>
                    <h3>Vital Signs</h3>
                </div>
                <?php if ($latest_vitals): ?>
                    <div style="font-size: 14px; line-height: 1.8; text-align: left; display: inline-block; margin: 0 auto;">
                        ❤️ <b>HR:</b> <?php echo $latest_vitals['heart_rate'] ?: '--'; ?> bpm<br>
                        🌡️ <b>Temp:</b> <?php echo $latest_vitals['temperature'] ? number_format($latest_vitals['temperature'], 1).'°C' : '--'; ?><br>
                        🩸 <b>BP:</b> <?php echo $latest_vitals['systolic_bp'] ? $latest_vitals['systolic_bp'].'/'.$latest_vitals['diastolic_bp'] : '--'; ?>
                    </div>
                <?php else: ?>
                    <p style="color: #aaa; font-size: 12px;">No records found</p>
                <?php endif; ?>
                <a href="patient-vitals.php" class="card-link">Details →</a>
            </div>

            <!-- Row 2, Col 2: Allergies -->
            <div class="card">
                <div>
                    <div class="card-icon" style="background: #f59e0b;">⚠️</div>
                    <h3>Allergies</h3>
                </div>
                <div class="stat-big" style="color: #f59e0b;"><?php echo $allergy_count; ?></div>
                <p class="stat-label">Registered Allergies</p>
                <a href="patient-allergies.php" class="card-link">Manage →</a>
            </div>

            <!-- Row 2, Col 3: Lab Results -->
            <div class="card">
                <div>
                    <div class="card-icon" style="background: #8b5cf6;">🧬</div>
                    <h3>Lab Results</h3>
                </div>
                <div class="stat-big" style="color: #8b5cf6;"><?php echo $completed_tests; ?></div>
                <p class="stat-label">Completed Reports</p>
                <a href="patient-lab-results.php" class="card-link">Results →</a>
            </div>
        </div>

        <div class="quick-actions">
            <h4 style="width: 100%; margin-bottom: 15px; font-size: 14px; color: var(--accent-green);">⚡ Quick Access</h4>
            <a href="patientappoint.php" class="action-btn">Book Appointment</a>
            <a href="patient-lab-results.php" class="action-btn">Lab Results</a>
            <a href="patient-allergies.php" class="action-btn">Manage Allergies</a>
            <a href="patientmedhist.php" class="action-btn">Medical Records</a>
            <a href="listofdoctor.php" class="action-btn">Find Specialists</a>
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