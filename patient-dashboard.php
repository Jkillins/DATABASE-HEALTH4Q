<?php
/**
 * patient-dashboard.php
 * Updated to 3-column, 2-row layout
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

    // 3. Fetch Appointments
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

    // 4. Fetch Active Prescriptions
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM prescription p
        JOIN medical_record mr ON p.record_id = mr.record_id
        WHERE mr.patient_id = ? AND p.status = 'active'
    ");
    $stmt->execute([$patient_id]);
    $active_prescriptions = $stmt->fetchColumn() ?: 0;

    // 5. Fetch Allergies
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM patient_allergy WHERE patient_id = ?");
    $stmt->execute([$patient_id]);
    $allergy_count = $stmt->fetchColumn() ?: 0;

    // 6. Fetch Latest Vital Signs
    $stmt = $pdo->prepare("
        SELECT vs.* FROM vital_signs vs
        JOIN medical_record mr ON vs.medical_record_id = mr.record_id
        WHERE mr.patient_id = ?
        ORDER BY vs.recorded_at DESC
        LIMIT 1
    ");
    $stmt->execute([$patient_id]);
    $latest_vitals = $stmt->fetch(PDO::FETCH_ASSOC);

    // 7. Fetch Lab Tests Count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM test_order t_order
        JOIN medical_record mr ON t_order.record_id = mr.record_id
        WHERE mr.patient_id = ? AND t_order.status = 'completed'
    ");
    $stmt->execute([$patient_id]);
    $completed_tests = $stmt->fetchColumn() ?: 0;

} catch (Exception $e) { 
    $all_appointments = [];
    $active_prescriptions = 0;
    $allergy_count = 0;
    $latest_vitals = null;
    $completed_tests = 0;
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
            background: radial-gradient(circle at center, #d8f3dc 0%, var(--light-bg) 100%);
            min-height: 100vh;
            color: var(--text-dark);
            display: flex;
            flex-direction: column;
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

        /* --- UPDATED GRID SYSTEM --- */
        .main-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr); /* Exactly 3 columns */
            grid-template-rows: auto auto;         /* 2 rows */
            gap: 25px;
            margin-bottom: 30px;
        }

        .card {
            background: var(--white);
            border-radius: 20px;
            padding: 25px;
            min-height: 280px; /* Adjusted height for 2-row layout */
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
    </style>
</head>
<body>

    <nav class="top-nav">
        <div class="nav-brand"><img src="images/Logo_only.png" alt="Health4Q"></div>
        <div class="nav-links">
            <a href="patient-dashboard.php" class="active">🏠 Dashboard</a>
            <a href="patientprofile.php">👤 Profile</a>
            <a href="patientappoint.php">📅 Appointments</a>
            <a href="patientmedhist.php">📜 History</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
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
</body>
</html>