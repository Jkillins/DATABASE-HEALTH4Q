<?php
/**
 * patient-dashboard.php
 * Enhanced UI version with Forest Green theme (Matches Doctor UI)
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

try {
    // 1. Fetch Patient Info
    $stmt = $pdo->prepare('SELECT u.*, p.* FROM users u JOIN patient p ON u.user_id = p.user_id WHERE u.user_id = ?');
    $stmt->execute([$user_id]);
    $patient = $stmt->fetch();
    $patient_id = $patient['patient_id'];

    // 2. Fetch Appointments
    $stmt = $pdo->prepare(
        "SELECT a.*, u.first_name as doc_fname, u.last_name as doc_lname, d.specialty 
         FROM appointment a 
         JOIN doctor d ON a.doctor_id = d.doctor_id
         JOIN users u ON d.user_id = u.user_id 
         WHERE a.patient_id = ? 
         ORDER BY a.schedule_start ASC"
    );
    $stmt->execute([$patient_id]);
    $all_appointments = $stmt->fetchAll();

    $next_appt = null;
    foreach($all_appointments as $appt) {
        if ($appt['status'] === 'scheduled' && strtotime($appt['schedule_start']) > time()) {
            $next_appt = $appt;
            break;
        }
    }

    // 3. Fetch Active Prescriptions
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM prescription p
        JOIN medical_record mr ON p.record_id = mr.record_id
        WHERE mr.patient_id = ? AND p.status = 'active'
    ");
    $stmt->execute([$patient_id]);
    $active_prescriptions = $stmt->fetch()['count'];

    // 4. Fetch Allergies
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM patient_allergy WHERE patient_id = ?
    ");
    $stmt->execute([$patient_id]);
    $allergy_count = $stmt->fetch()['count'];

    // 5. Fetch Latest Vital Signs
    $stmt = $pdo->prepare("
        SELECT vs.*, mr.appointment_id FROM vital_signs vs
        JOIN medical_record mr ON vs.medical_record_id = mr.record_id
        WHERE mr.patient_id = ?
        ORDER BY vs.recorded_at DESC
        LIMIT 1
    ");
    $stmt->execute([$patient_id]);
    $latest_vitals = $stmt->fetch();

    // 6. Fetch Lab Tests Count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM test_order to
        JOIN medical_record mr ON to.record_id = mr.record_id
        WHERE mr.patient_id = ? AND to.status = 'completed'
    ");
    $stmt->execute([$patient_id]);
    $completed_tests = $stmt->fetch()['count'];

    // 7. Fetch Emergency Contacts
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM patient_emergency_contact WHERE patient_id = ?
    ");
    $stmt->execute([$patient_id]);
    $emergency_contact_count = $stmt->fetch()['count'];

} catch (Exception $e) { 
    $all_appointments = [];
    $active_prescriptions = 0;
    $allergy_count = 0;
    $latest_vitals = null;
    $completed_tests = 0;
    $emergency_contact_count = 0;
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
            --primary-green: #1a4d34; /* Dark Forest Green */
            --light-bg: #c5e6e1;    /* Pale Mint Background */
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

        /* --- DASHBOARD LAYOUT --- */
        .container { max-width: 1100px; margin: 30px auto; padding: 0 20px; flex: 1; }

        .welcome-card {
            background: var(--white);
            padding: 35px;
            border-radius: 20px;
            margin-bottom: 30px;
            border-bottom: 6px solid #84ccb1;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
        }

        .welcome-card h1 { font-size: 2rem; color: var(--accent-green); font-weight: 800; margin-bottom: 10px; }
        .welcome-card p { color: #555; font-size: 14px; }
        
        .date-badge {
            display: inline-block;
            background: #e9f5f2;
            padding: 6px 16px;
            border-radius: 12px;
            color: #4361ee;
            font-size: 12px;
            font-weight: 600;
            margin-top: 20px;
            border: 1px solid #d1e9e3;
        }

        /* --- UPDATED GRID LAYOUT --- */
        .main-grid {
            display: grid;
            /* This creates 4 equal columns */
            grid-template-columns: repeat(4, 1fr); 
            gap: 20px;
            margin-bottom: 30px;
            /* Removed the 600px max-width to allow the grid to expand */
            max-width: 1200px; 
            margin-left: auto;
            margin-right: auto;
        }

        .card {
            background: var(--white);
            border-radius: 20px;
            padding: 20px; /* Slightly reduced padding for better fit */
            min-height: 300px; /* Adjusted height */
            box-shadow: 0 10px 25px rgba(0,0,0,0.04);
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        /* --- RESPONSIVE DESIGN --- */
        /* Tablets: 2 columns */
        @media (max-width: 1024px) { 
            .main-grid { grid-template-columns: repeat(2, 1fr); } 
        }

        /* Phones: 1 column */
        @media (max-width: 600px) { 
            .main-grid { grid-template-columns: 1fr; } 
            .quick-actions { flex-direction: column; align-items: stretch; }
        }

        .card-icon {
            width: 55px; height: 55px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px; color: white; font-size: 22px;
        }

        .card h3 { font-size: 18px; margin-bottom: 25px; color: var(--text-dark); }

        .stat-big { font-size: 48px; font-weight: 800; color: var(--accent-green); margin: 10px 0; }
        .stat-label { font-size: 11px; color: #888; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 700; }

        .card-link {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background: var(--primary-green);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            transition: 0.3s;
        }

        .card-link:hover {
            background: var(--accent-green);
        }

        .card-data {
            margin-top: 20px;
            font-size: 13px;
            color: #666;
            line-height: 1.8;
        }

        /* --- LIST STYLING --- */
        .list-table { width: 100%; border-collapse: collapse; font-size: 12px; text-align: left; }
        .list-table td { padding: 10px 5px; border-bottom: 1px solid #f5f5f5; }

        .status-pill {
            padding: 3px 8px; border-radius: 5px; font-size: 10px; font-weight: 700;
        }
        .status-scheduled { background: #e0f2fe; color: #0369a1; }
        .status-completed { background: #dcfce7; color: #15803d; }

        /* --- QUICK ACTIONS --- */
        .quick-actions {
            background: var(--white);
            padding: 20px 30px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            gap: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.03);
        }
        .quick-actions h4 { color: #e67e22; font-size: 14px; white-space: nowrap; }
        
        .action-btn {
            flex: 1; padding: 12px;
            background: var(--primary-green); color: white;
            text-decoration: none; border-radius: 10px;
            font-size: 12px; font-weight: 600; text-align: center;
            transition: 0.3s;
        }
        .action-btn:hover { background: var(--accent-green); transform: translateY(-3px); }

        @media (max-width: 992px) { .main-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

    <nav class="top-nav">
        <div class="nav-brand">
            <img src="images/Logo_only.png" alt="Health4Q">
        </div>
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
            <div class="date-badge">
                📅 <?php echo date('l, F d, Y'); ?>
            </div>
        </div>

        <div class="main-grid">
            
            <!-- Health Profile Card -->
            <div class="card">
                <div class="card-icon" style="background: #ef4444;">❤️</div>
                <h3>Health Profile</h3>
                <div style="margin-top: 20px;">
                    <p class="stat-label">Blood Type</p>
                    <div class="stat-big" style="color: #ef4444; font-size: 36px;"><?php echo $patient['blood_type'] ?? '--'; ?></div>
                    <p class="stat-label" style="margin-top: 15px;">Age</p>
                    <p style="font-size: 20px; font-weight: 700;">
                        <?php 
                            if ($patient['date_of_birth']) {
                                $dob = new DateTime($patient['date_of_birth']);
                                $today = new DateTime();
                                $age = $today->diff($dob)->y;
                                echo $age . ' years';
                            } else {
                                echo 'Not Set';
                            }
                        ?>
                    </p>
                </div>
                <a href="patientprofile.php" class="card-link">Update Profile →</a>
            </div>

            <!-- Prescriptions Card -->
            <div class="card">
                <div class="card-icon" style="background: #84cc16;">💊</div>
                <h3>Prescriptions</h3>
                <div class="stat-big" style="color: #84cc16;"><?php echo $active_prescriptions; ?></div>
                <p class="stat-label">Active Prescriptions</p>
                <div class="card-data">
                    <p>View and manage your medications</p>
                </div>
                <a href="patient-prescriptions.php" class="card-link">View All →</a>
            </div>

            <!-- Recent Appointments Card -->
            <div class="card">
                <div class="card-icon" style="background: #4361ee;">📅</div>
                <h3>Recent Appointments</h3>
                <table class="list-table">
                    <?php if ($all_appointments): ?>
                        <?php foreach (array_slice($all_appointments, 0, 3) as $appt): ?>
                        <tr>
                            <td>
                                <strong>Dr. <?php echo htmlspecialchars($appt['doc_lname']); ?></strong><br>
                                <small style="color: #888;"><?php echo date('M d', strtotime($appt['schedule_start'])); ?></small>
                            </td>
                            <td style="text-align: right;">
                                <span class="status-pill status-<?php echo strtolower($appt['status']); ?>">
                                    <?php echo strtoupper(substr($appt['status'], 0, 3)); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="2" style="text-align: center; color: #aaa; padding: 20px 0;">No appointments yet</td></tr>
                    <?php endif; ?>
                </table>
                <a href="patientappoint.php" class="card-link">Book Now →</a>
            </div>

            <!-- Vital Signs Card -->
            <div class="card">
                <div class="card-icon" style="background: #ef4444;">💓</div>
                <h3>Vital Signs</h3>
                <?php if ($latest_vitals): ?>
                    <div class="card-data">
                        <strong>Last Recorded:</strong><br>
                        <?php echo date('M d, Y', strtotime($latest_vitals['recorded_at'])); ?>
                    </div>
                    <div class="card-data">
                        <?php if ($latest_vitals['heart_rate']): ?>
                            ❤️ HR: <?php echo $latest_vitals['heart_rate']; ?> bpm<br>
                        <?php endif; ?>
                        <?php if ($latest_vitals['temperature']): ?>
                            🌡️ Temp: <?php echo number_format($latest_vitals['temperature'], 1); ?>°C<br>
                        <?php endif; ?>
                        <?php if ($latest_vitals['systolic_bp']): ?>
                            BP: <?php echo $latest_vitals['systolic_bp']; ?>/<?php echo $latest_vitals['diastolic_bp']; ?> mmHg
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="card-data">
                        <p style="color: #aaa;">No vital signs recorded yet</p>
                    </div>
                <?php endif; ?>
                <a href="patient-vitals.php" class="card-link">View Details →</a>
            </div>

            <!-- Allergies Card -->
            <div class="card">
                <div class="card-icon" style="background: #f59e0b;">⚠️</div>
                <h3>Allergies</h3>
                <div class="stat-big" style="color: #f59e0b;"><?php echo $allergy_count; ?></div>
                <p class="stat-label">Known Allergies</p>
                <div class="card-data">
                    <p>Keep your medical team informed</p>
                </div>
                <a href="patient-allergies.php" class="card-link">Manage →</a>
            </div>

            <!-- Lab Results Card -->
            <div class="card">
                <div class="card-icon" style="background: #8b5cf6;">🧬</div>
                <h3>Lab Results</h3>
                <div class="stat-big" style="color: #8b5cf6;"><?php echo $completed_tests; ?></div>
                <p class="stat-label">Completed Tests</p>
                <div class="card-data">
                    <p>View your test results</p>
                </div>
                <a href="patient-lab-results.php" class="card-link">View Tests →</a>
            </div>

            <!-- Activity Overview Card -->
            <div class="card">
                <div class="card-icon" style="background: #7209b7;">📊</div>
                <h3>Activity Overview</h3>
                <div class="stat-big" style="color: #7209b7;"><?php echo count($all_appointments); ?></div>
                <p class="stat-label">Total Consultations</p>
                <div class="card-data">
                    <p>Keep track of your health journey</p>
                </div>
            </div>

        </div>

        <div class="quick-actions">
            <h4>⚡ Quick Actions</h4>
            <a href="patientappoint.php" class="action-btn">Book Appointment</a>
            <a href="patient-lab-results.php" class="action-btn">Lab Results</a>
            <a href="patient-allergies.php" class="action-btn">Manage Allergies</a>
            <a href="patientmedhist.php" class="action-btn">Medical Records</a>
            <a href="listofdoctor.php" class="action-btn">Find Specialists</a>
        </div>
    </div>

</body>
</html>