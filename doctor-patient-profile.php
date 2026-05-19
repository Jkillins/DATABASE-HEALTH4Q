<?php
/**
 * doctor-patient-profile.php
 */

require_once 'config.php';
requireRole(ROLE_DOCTOR);

$pdo = getPDO();
$doctor_id = getCurrentRoleId();

$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

if (!$patient_id) {
    header('Location: doctor-patient-list.php');
    exit;
}

// Get patient profile
$stmt = $pdo->prepare('
    SELECT u.*, p.*, a.zipcode, a.barangay, a.city, a.province
    FROM patient p
    JOIN users u ON p.user_id = u.user_id
    LEFT JOIN address a ON u.user_id = a.user_id
    WHERE p.patient_id = ?
');
$stmt->execute([$patient_id]);
$patient = $stmt->fetch();

if (!$patient) {
    header('Location: doctor-patient-list.php');
    exit;
}

// Get appointment count
$stmt = $pdo->prepare('
    SELECT COUNT(*) as count FROM appointment
    WHERE patient_id = ? AND doctor_id = ?
');
$stmt->execute([$patient_id, $doctor_id]);
$apt_count = $stmt->fetch()['count'];

// Get medical records
$stmt = $pdo->prepare('
    SELECT mr.*, d.specialty FROM medical_record mr
    LEFT JOIN doctor d ON mr.doctor_id = d.doctor_id
    WHERE mr.patient_id = ?
    ORDER BY mr.date_time DESC LIMIT 20
');
$stmt->execute([$patient_id]);
$medical_records = $stmt->fetchAll();

// Calculate age
function calculateAge($dob) {
    if (!$dob) return 'N/A';
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    return $today->diff($birthDate)->y;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Profile | Health4Q</title>
    <link rel="icon" type="image/png" href="images/Logo_only.png">
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-green: #1a4d34;
            --accent-green: #2d6a4f;
            --light-bg: #c5e6e1;
            --white: #ffffff;
            --text-dark: #1b4332;
            --text-light: #555;
            --border-color: #d0e8e0;
            --danger: #d90429;
            --success: #52b788;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Quicksand', sans-serif;
            background: radial-gradient(circle at center, #d8f3dc 0%, var(--light-bg) 100%);
            min-height: 100vh;
            color: var(--text-dark);
        }

        /* Navigation */
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
            background: var(--danger);
            color: white;
            padding: 8px 18px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            font-size: 12px;
            transition: 0.3s;
        }
        .logout-btn:hover { background: #b80322; }

        /* Container */
        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: var(--primary-green);
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 20px;
            transition: 0.3s;
        }
        .back-link:hover { color: var(--accent-green); }

        /* Profile Header */
        .profile-header {
            background: var(--white);
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 30px;
            align-items: start;
        }

        .avatar {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, var(--accent-green), var(--primary-green));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 48px;
            font-weight: 700;
        }

        .profile-info h1 {
            font-size: 28px;
            color: var(--primary-green);
            margin-bottom: 10px;
        }

        .profile-meta {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .meta-item {
            font-size: 13px;
            color: var(--text-light);
        }

        .meta-label { font-weight: 600; color: var(--text-dark); }

        .profile-stats {
            display: flex;
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }

        .stat-box {
            background: var(--light-bg);
            padding: 15px;
            border-radius: 8px;
        }

        .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: var(--accent-green);
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 5px;
        }

        /* Tabs */
        .tabs {
            display: flex;
            gap: 10px;
            border-bottom: 2px solid var(--border-color);
            margin-bottom: 25px;
            background: var(--white);
            padding: 15px 20px;
            border-radius: 12px 12px 0 0;
        }

        .tab-btn {
            padding: 10px 20px;
            background: none;
            border: none;
            color: var(--text-light);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.3s;
            border-bottom: 3px solid transparent;
            margin-bottom: -15px;
        }

        .tab-btn.active {
            color: var(--accent-green);
            border-bottom-color: var(--accent-green);
        }

        .tab-content {
            display: none;
            background: var(--white);
            border-radius: 0 0 12px 12px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        .tab-content.active { display: block; }

        /* Contact Information */
        .info-group {
            margin-bottom: 20px;
        }

        .info-group label {
            display: block;
            font-weight: 600;
            color: var(--primary-green);
            margin-bottom: 5px;
            font-size: 13px;
        }

        .info-value {
            font-size: 14px;
            color: var(--text-dark);
            padding: 10px;
            background: var(--light-bg);
            border-radius: 6px;
        }

        /* Medical Records List */
        .records-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .record-item {
            background: var(--light-bg);
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid var(--accent-green);
        }

        .record-date {
            font-weight: 600;
            color: var(--primary-green);
            font-size: 13px;
            margin-bottom: 8px;
        }

        .record-diagnosis {
            font-size: 14px;
            color: var(--text-dark);
            margin-bottom: 8px;
        }

        .record-notes {
            font-size: 13px;
            color: var(--text-light);
            line-height: 1.5;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
        }

        .empty-icon { font-size: 40px; margin-bottom: 10px; }

        /* Action Button */
        .action-btn {
            display: inline-block;
            padding: 10px 20px;
            background: var(--accent-green);
            color: white;
            text-decoration: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 13px;
            transition: 0.3s;
            border: none;
            cursor: pointer;
        }
        .action-btn:hover { background: var(--primary-green); }

        /* Responsive */
        @media (max-width: 768px) {
            .profile-header { grid-template-columns: 1fr; }
            .profile-stats { flex-direction: row; }
            .tabs { flex-wrap: wrap; }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <div class="top-nav">
        <div class="nav-brand">
            <img src="images/Logo_only.png" alt="Health4Q">
        </div>
        <div class="nav-links">
            <a href="doctor-dashboard.php">Dashboard</a>
            <a href="doctor-patient-list.php">Patients</a>
            <a href="doctor-availability.php">Availability</a>
            <a href="doctor-profile.php">Profile</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>

    <!-- Main Content -->
    <div class="container">
        <a href="doctor-patient-list.php" class="back-link">← Back to Patient List</a>

        <!-- Profile Header -->
        <div class="profile-header">
            <div class="avatar">
                <?php echo strtoupper(substr($patient['first_name'], 0, 1) . substr($patient['last_name'], 0, 1)); ?>
            </div>
            <div class="profile-info">
                <h1><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h1>
                <div class="profile-meta">
                    <div class="meta-item">
                        <div class="meta-label">Age</div>
                        <?php echo calculateAge($patient['date_of_birth']); ?> years
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Gender</div>
                        <?php echo ucfirst($patient['sex'] ?? 'N/A'); ?>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Blood Type</div>
                        <strong style="color: #d90429; font-size: 14px;"><?php echo htmlspecialchars(($patient['blood_type'] ?? '') ?: 'Not Specified'); ?></strong>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Patient ID</div>
                        #<?php echo str_pad($patient_id, 5, '0', STR_PAD_LEFT); ?>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Member Since</div>
                        <?php echo date('M d, Y', strtotime($patient['created_at'])); ?>
                    </div>
                </div>
            </div>
            <div class="profile-stats">
                <div class="stat-box">
                    <div class="stat-number"><?php echo $apt_count; ?></div>
                    <div class="stat-label">Total Visits</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo count($medical_records); ?></div>
                    <div class="stat-label">Medical Records</div>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-btn active" onclick="showTab('contact')">Contact Info</button>
            <button class="tab-btn" onclick="showTab('medical')">Medical Records</button>
        </div>

        <!-- Contact Information Tab -->
        <div id="contact" class="tab-content active">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                <div class="info-group">
                    <label>Email</label>
                    <div class="info-value"><?php echo htmlspecialchars($patient['email']); ?></div>
                </div>
                <div class="info-group">
                    <label>Phone</label>
                    <div class="info-value"><?php echo $patient['contact_no'] ? htmlspecialchars($patient['contact_no']) : 'Not provided'; ?></div>
                </div>
                <div class="info-group">
                    <label>Date of Birth</label>
                    <div class="info-value"><?php echo $patient['date_of_birth'] ? date('M d, Y', strtotime($patient['date_of_birth'])) : 'Not provided'; ?></div>
                </div>
                <div class="info-group">
                    <label>Gender</label>
                    <div class="info-value"><?php echo ucfirst($patient['sex'] ?? 'Not specified'); ?></div>
                </div>
                <div class="info-group">
                    <label>Blood Type</label>
                    <div class="info-value"><strong style="color: #d90429;"><?php echo htmlspecialchars(($patient['blood_type'] ?? '') ?: 'Not Specified'); ?></strong></div>
                </div>
                <div class="info-group">
                    <label>City</label>
                    <div class="info-value"><?php echo htmlspecialchars($patient['city'] ?? 'Not provided'); ?></div>
                </div>
                <div class="info-group">
                    <label>Province</label>
                    <div class="info-value"><?php echo htmlspecialchars($patient['province'] ?? 'Not provided'); ?></div>
                </div>
            </div>
        </div>

        <!-- Medical Records Tab -->
        <div id="medical" class="tab-content">
            <a href="doctor-medical-records.php?patient_id=<?php echo $patient_id; ?>" class="action-btn">View All Medical Records →</a>
            <div style="margin-top: 20px;">
                <?php if (count($medical_records) > 0): ?>
                    <div class="records-list">
                        <?php foreach ($medical_records as $record): ?>
                            <div class="record-item">
                                <div class="record-date">
                                    📅 <?php echo date('M d, Y h:i A', strtotime($record['date_time'])); ?>
                                </div>
                                <div class="record-diagnosis">
                                    <strong>Diagnosis:</strong> <?php echo htmlspecialchars($record['diagnosis'] ?? 'No diagnosis recorded'); ?>
                                </div>
                                <?php if ($record['treatment_summary']): ?>
                                    <div class="record-notes">
                                        <strong>Treatment:</strong> <?php echo htmlspecialchars($record['treatment_summary']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">📋</div>
                        <p>No medical records found for this patient.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));

            // Show selected tab
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }
    </script>
</body>
</html>
