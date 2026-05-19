<?php
/**
 * assistant-patient-search.php - Premium Patient Clinical Overview & Search Center
 */
require_once 'config.php';
requireRole(ROLE_ASSISTANT);

$pdo = getPDO();

$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

$patients = [];
$selected_patient = null;
$patient_history = [];
$insurance = null;
$current_queue = null;
$allergies = [];
$latest_vitals = null;

// Search for patients
if ($search) {
    try {
        $stmt = $pdo->prepare('
            SELECT p.patient_id, u.first_name, u.last_name, u.email, u.contact_no, p.date_of_birth, p.sex
            FROM patient p
            JOIN users u ON p.user_id = u.user_id
            WHERE u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.contact_no LIKE ?
            ORDER BY u.last_name ASC
            LIMIT 100
        ');
        $search_term = "%$search%";
        $stmt->execute([$search_term, $search_term, $search_term, $search_term]);
        $patients = $stmt->fetchAll();
    } catch (Exception $e) {
        $patients = [];
    }
}

// Get selected patient details
if ($patient_id) {
    try {
        $stmt = $pdo->prepare('
            SELECT u.*, p.* FROM patient p
            JOIN users u ON p.user_id = u.user_id
            WHERE p.patient_id = ?
        ');
        $stmt->execute([$patient_id]);
        $selected_patient = $stmt->fetch();

        if ($selected_patient) {
            // 1. Get medical records
            $stmt = $pdo->prepare('
                SELECT mr.*, d.specialty, u_d.first_name, u_d.last_name
                FROM medical_record mr
                JOIN doctor d ON mr.doctor_id = d.doctor_id
                JOIN users u_d ON d.user_id = u_d.user_id
                WHERE mr.patient_id = ?
                ORDER BY mr.date_time DESC
                LIMIT 50
            ');
            $stmt->execute([$patient_id]);
            $patient_history = $stmt->fetchAll();

            // 2. Get Insurance Details
            $stmtIns = $pdo->prepare('SELECT * FROM patient_insurance WHERE patient_id = ? LIMIT 1');
            $stmtIns->execute([$patient_id]);
            $insurance = $stmtIns->fetch(PDO::FETCH_ASSOC);

            // 3. Get Active Queue Status for Today
            $stmtQueue = $pdo->prepare('
                SELECT pq.*, u_d.last_name as doctor_lname 
                FROM patient_queue pq
                JOIN doctor d ON pq.doctor_id = d.doctor_id
                JOIN users u_d ON d.user_id = u_d.user_id
                WHERE pq.patient_id = ? AND DATE(pq.check_in_time) = CURDATE()
                ORDER BY pq.queue_id DESC LIMIT 1
            ');
            $stmtQueue->execute([$patient_id]);
            $current_queue = $stmtQueue->fetch(PDO::FETCH_ASSOC);

            // 4. Get Allergies
            $stmtAllergies = $pdo->prepare('SELECT * FROM patient_allergy WHERE patient_id = ? ORDER BY severity DESC');
            $stmtAllergies->execute([$patient_id]);
            $allergies = $stmtAllergies->fetchAll();

            // 5. Get Latest Vital Signs
            $stmtVitals = $pdo->prepare('
                SELECT vs.* FROM vital_signs vs
                JOIN medical_record mr ON vs.medical_record_id = mr.record_id
                WHERE mr.patient_id = ?
                ORDER BY vs.recorded_at DESC LIMIT 1
            ');
            $stmtVitals->execute([$patient_id]);
            $latest_vitals = $stmtVitals->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        $selected_patient = null;
    }
}

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
    <title>Patient Clinical Finder | Health4Q</title>
    <link rel="icon" type="image/png" href="images/Logo_only.png">
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-green: #1a4d34;
            --accent-green: #2d6a4f;
            --light-bg: #c5e6e1;
            --white: #ffffff;
            --text-dark: #1b4332;
            --text-light: #666;
            --border-color: #d0e8e0;
            --danger: #d90429;
            --info: #4361ee;
            --warning: #f59e0b;
            --success: #16a34a;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Quicksand', sans-serif;
            background: radial-gradient(circle at center, #d8f3dc 0%, var(--light-bg) 100%);
            min-height: 100vh;
            color: var(--text-dark);
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
        .nav-links { display: flex; gap: 8px; }
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
            padding: 8px 16px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            font-size: 12px;
        }

        .container {
            max-width: 1250px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        h1 {
            font-size: 28px;
            color: var(--primary-green);
            margin-bottom: 25px;
        }

        .search-section {
            background: var(--white);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 6px 20px rgba(27, 67, 50, 0.05);
        }

        .search-form {
            display: flex;
            gap: 10px;
        }

        .search-form input {
            flex: 1;
            padding: 14px;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            font-family: inherit;
            font-size: 14px;
        }

        .search-form input:focus {
            outline: none;
            border-color: var(--accent-green);
        }

        .search-btn {
            background: var(--accent-green);
            color: white;
            padding: 14px 35px;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.3s;
        }

        .search-btn:hover { background: var(--primary-green); }

        .results-grid {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 25px;
        }

        .patients-list {
            background: var(--white);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 6px 20px rgba(0,0,0,0.05);
            max-height: 800px;
            overflow-y: auto;
        }

        .patient-item {
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-color);
            cursor: pointer;
            transition: 0.2s;
        }

        .patient-item:hover { background: #f0f7f5; }
        .patient-item.selected { background: #e0f2ed; border-left: 5px solid var(--accent-green); }

        .patient-name {
            font-weight: 700;
            color: var(--primary-green);
            font-size: 13px;
        }

        .patient-email {
            font-size: 11px;
            color: var(--text-light);
            margin-top: 3px;
        }

        /* Patient Detailed Overview Styling */
        .patient-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }

        @media (max-width: 1100px) {
            .patient-details-grid { grid-template-columns: 1fr; }
            .results-grid { grid-template-columns: 1fr; }
        }

        .card {
            background: var(--white);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.04);
            margin-bottom: 25px;
            border: 1px solid rgba(255,255,255,0.6);
        }

        .card-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--primary-green);
            margin-bottom: 15px;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .badge-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .pill {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .pill-queue { background: #e0e7ff; color: #4361ee; border: 1px solid #c7d2fe; }
        .pill-allergy { background: #fee2e2; color: #ef4444; border: 1px solid #fecdd3; }

        .info-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .info-table td {
            padding: 8px 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .info-table td.lbl {
            font-weight: 700;
            color: var(--accent-green);
            width: 120px;
        }

        .history-timeline {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .timeline-item {
            background: #f8fafc;
            padding: 15px;
            border-radius: 10px;
            border-left: 4px solid var(--accent-green);
            border: 1px solid #e2e8f0;
            border-left: 4px solid var(--accent-green);
        }

        .timeline-date {
            font-size: 11px;
            font-weight: 700;
            color: #888;
            margin-bottom: 5px;
        }

        .timeline-body {
            font-size: 12px;
            color: var(--text-dark);
            line-height: 1.5;
        }

        .vitals-display {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            text-align: center;
        }

        .vital-box {
            background: #f0fdf4;
            padding: 10px;
            border-radius: 10px;
            border: 1px solid #bbf7d0;
        }

        .vital-val {
            font-size: 16px;
            font-weight: 800;
            color: var(--accent-green);
        }

        .vital-lbl {
            font-size: 10px;
            color: #666;
            margin-top: 3px;
            text-transform: uppercase;
        }

        .allergy-item {
            padding: 10px;
            border-radius: 8px;
            background: #fff5f5;
            border: 1px solid #ffe3e3;
            margin-bottom: 8px;
            font-size: 12px;
        }

        .severity-badge {
            font-size: 9px;
            font-weight: 800;
            padding: 2px 6px;
            border-radius: 4px;
            text-transform: uppercase;
            display: inline-block;
            margin-left: 5px;
        }

        .severity-mild { background: #d1ecf1; color: #0c5460; }
        .severity-moderate { background: #fff3cd; color: #856404; }
        .severity-severe { background: #f8d7da; color: #721c24; }

        .empty-state {
            padding: 40px;
            text-align: center;
            color: var(--text-light);
        }
    </style>
</head>
<body>

    <div class="top-nav">
        <div class="nav-brand"><img src="images/Logo_only.png" alt="Health4Q"></div>
        <div class="nav-links">
            <a href="assistant-dashboard.php">Overview</a>
            <a href="assistant-queue.php">📋 Live Queue</a>
            <a href="assistant-broadcast.php">📢 Alerts</a>
            <a href="assistant-referral.php">📤 Referrals</a>
            <a href="assistant-inventory.php">📦 Supplies</a>
            <a href="assistant-patient-search.php" class="active">🔍 Search</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>

    <div class="container">
        <h1>🔍 Clinical Patient Directory</h1>

        <!-- Search Bar -->
        <div class="search-section">
            <form method="GET" class="search-form">
                <input type="text" name="search" placeholder="Search by patient name, email, or contact number..." value="<?php echo htmlspecialchars($search); ?>" required>
                <button type="submit" class="search-btn">🔍 Search</button>
            </form>
        </div>

        <!-- Main Layout -->
        <?php if ($search || $patient_id): ?>
            <div class="results-grid">
                
                <!-- Patient List Panel -->
                <div class="patients-list">
                    <?php if (count($patients) > 0): ?>
                        <?php foreach ($patients as $p): ?>
                            <div class="patient-item <?php echo $p['patient_id'] === $patient_id ? 'selected' : ''; ?>">
                                <a href="?search=<?php echo urlencode($search); ?>&patient_id=<?php echo $p['patient_id']; ?>" style="text-decoration: none; color: inherit;">
                                    <div class="patient-name">👤 <?php echo htmlspecialchars($p['last_name'] . ', ' . $p['first_name']); ?></div>
                                    <div class="patient-email">📧 <?php echo htmlspecialchars($p['email']); ?> | 📞 <?php echo htmlspecialchars($p['contact_no'] ?: 'No Phone'); ?></div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">No matching patients found.</div>
                    <?php endif; ?>
                </div>

                <!-- Patient Profile Details Panel -->
                <div>
                    <?php if ($selected_patient): ?>
                        
                        <!-- Top Banner Profile Card -->
                        <div class="card" style="border-left: 8px solid var(--accent-green);">
                            <h2 style="font-size: 22px; color: var(--primary-green); margin-bottom: 10px;">
                                👤 <?php echo htmlspecialchars($selected_patient['first_name'] . ' ' . $selected_patient['last_name']); ?>
                            </h2>
                            <p style="font-size: 13px; color: #555; margin-bottom: 15px;">
                                Patient Reference Ticket: <strong>#PAT-<?php echo str_pad($selected_patient['patient_id'], 5, '0', STR_PAD_LEFT); ?></strong>
                            </p>

                            <!-- Live Alerts Row -->
                            <div class="badge-bar">
                                <?php if ($current_queue): ?>
                                    <span class="pill pill-queue">
                                        📋 checked In (Ticket #<?php echo $current_queue['queue_position']; ?>) | Status: <?php echo ucfirst($current_queue['status']); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (count($allergies) > 0): ?>
                                    <span class="pill pill-allergy">
                                        ⚠️ <?php echo count($allergies); ?> Active Allergies Registered
                                    </span>
                                <?php endif; ?>
                            </div>

                            <!-- Basic Demographics -->
                            <table class="info-table">
                                <tr>
                                    <td class="lbl">Email Address</td>
                                    <td><?php echo htmlspecialchars($selected_patient['email']); ?></td>
                                </tr>
                                <tr>
                                    <td class="lbl">Contact Number</td>
                                    <td><?php echo htmlspecialchars($selected_patient['contact_no'] ?: 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <td class="lbl">Age / Gender</td>
                                    <td><?php echo calculateAge($selected_patient['date_of_birth']); ?> years / <?php echo ucfirst($selected_patient['sex'] ?? 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <td class="lbl">Date of Birth</td>
                                    <td><?php echo $selected_patient['date_of_birth'] ? date('M d, Y', strtotime($selected_patient['date_of_birth'])) : 'N/A'; ?></td>
                                </tr>
                                <tr>
                                    <td class="lbl">Blood Type</td>
                                    <td><strong style="color: #d90429; font-size: 13px;"><?php echo htmlspecialchars(($selected_patient['blood_type'] ?? '') ?: 'Not Specified'); ?></strong></td>
                                </tr>
                            </table>
                        </div>

                        <!-- Secondary Demographic Detail Columns -->
                        <div class="patient-details-grid">
                            
                            <!-- Left Column: Insurance, Vitals, Allergies -->
                            <div>
                                <!-- Insurance Card -->
                                <div class="card">
                                    <div class="card-title">💳 Insurance & Coverage</div>
                                    <?php if ($insurance): ?>
                                        <table class="info-table" style="font-size: 12px;">
                                            <tr>
                                                <td class="lbl" style="width:100px;">Provider</td>
                                                <td><strong><?php echo htmlspecialchars($insurance['provider_name']); ?></strong></td>
                                            </tr>
                                            <tr>
                                                <td class="lbl">Policy Number</td>
                                                <td><?php echo htmlspecialchars($insurance['policy_number']); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="lbl">Group Number</td>
                                                <td><?php echo htmlspecialchars($insurance['group_number'] ?: 'N/A'); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="lbl">Plan Type</td>
                                                <td><?php echo htmlspecialchars($insurance['plan_type'] ?: 'N/A'); ?></td>
                                            </tr>
                                            <tr>
                                                <td class="lbl">Validity</td>
                                                <td>
                                                    <span style="font-weight:700; color: <?php echo (strtotime($insurance['expiry_date'] ?? '') >= time()) ? 'var(--success)' : 'var(--danger)'; ?>">
                                                        Expiry: <?php echo $insurance['expiry_date'] ? date('M d, Y', strtotime($insurance['expiry_date'])) : 'Never'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        </table>
                                    <?php else: ?>
                                        <p style="color: #aaa; font-size: 12px; text-align:center; padding: 15px 0;">No active insurance coverage on file.</p>
                                    <?php endif; ?>
                                </div>

                                <!-- Vital Signs Card -->
                                <div class="card">
                                    <div class="card-title">💓 Latest Vital Signs</div>
                                    <?php if ($latest_vitals): ?>
                                        <div class="vitals-display">
                                            <div class="vital-box">
                                                <div class="vital-val"><?php echo $latest_vitals['heart_rate'] ?: '--'; ?></div>
                                                <div class="vital-lbl">HR (BPM)</div>
                                            </div>
                                            <div class="vital-box">
                                                <div class="vital-val"><?php echo $latest_vitals['temperature'] ? number_format($latest_vitals['temperature'], 1).'°C' : '--'; ?></div>
                                                <div class="vital-lbl">Temp</div>
                                            </div>
                                            <div class="vital-box">
                                                <div class="vital-val"><?php echo $latest_vitals['systolic_bp'] ? $latest_vitals['systolic_bp'].'/'.$latest_vitals['diastolic_bp'] : '--'; ?></div>
                                                <div class="vital-lbl">BP (mmHg)</div>
                                            </div>
                                        </div>
                                        <p style="font-size: 10px; color: #888; margin-top: 15px; text-align: right;">
                                            Recorded: <?php echo date('M d, Y h:i A', strtotime($latest_vitals['recorded_at'])); ?>
                                        </p>
                                    <?php else: ?>
                                        <p style="color: #aaa; font-size: 12px; text-align:center; padding: 15px 0;">No vital sign records found.</p>
                                    <?php endif; ?>
                                </div>

                                <!-- Allergies Card -->
                                <div class="card">
                                    <div class="card-title">⚠️ Patient Allergies</div>
                                    <?php if (count($allergies) > 0): ?>
                                        <?php foreach ($allergies as $a): ?>
                                            <div class="allergy-item">
                                                <strong><?php echo htmlspecialchars($a['allergen_name']); ?></strong>
                                                <span class="severity-badge severity-<?php echo htmlspecialchars($a['severity']); ?>">
                                                    <?php echo htmlspecialchars($a['severity']); ?>
                                                </span>
                                                <div style="font-size: 10px; color: #666; margin-top: 4px;">
                                                    Type: <?php echo ucfirst(htmlspecialchars($a['allergen_type'])); ?>
                                                    <?php if ($a['reaction']): ?>
                                                        | Reaction: <?php echo htmlspecialchars($a['reaction']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p style="color: #aaa; font-size: 12px; text-align:center; padding: 15px 0;">No known allergies registered.</p>
                                    <?php endif; ?>
                                </div>

                            </div>

                            <!-- Right Column: Medical History Timeline -->
                            <div>
                                <div class="card">
                                    <div class="card-title">📋 Clinical Medical History</div>
                                    <?php if (count($patient_history) > 0): ?>
                                        <div class="history-timeline">
                                            <?php foreach ($patient_history as $record): ?>
                                                <div class="timeline-item">
                                                    <div class="timeline-date">📅 <?php echo date('F d, Y \a\t h:i A', strtotime($record['date_time'])); ?></div>
                                                    <div class="timeline-body">
                                                        <div style="margin-bottom: 5px;">
                                                            <strong>Diagnosis:</strong> <span style="color: var(--accent-green);"><?php echo htmlspecialchars($record['diagnosis'] ?: 'N/A'); ?></span>
                                                        </div>
                                                        <?php if ($record['treatment_summary']): ?>
                                                            <div style="margin-bottom: 5px;">
                                                                <strong>Treatment:</strong> <?php echo htmlspecialchars($record['treatment_summary']); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                        <div style="font-size: 10px; color:#888;">
                                                            Consultant: Dr. <?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?> (<?php echo htmlspecialchars($record['specialty']); ?>)
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="empty-state" style="color: #aaa; font-size: 12px; padding: 30px 0;">No past medical history records available.</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div>

                    <?php else: ?>
                        <div class="card">
                            <div class="empty-state">
                                <h3>👤 Patient Profile Finder</h3>
                                <p>Select a patient from the search results list on the left to inspect their complete clinical folder, insurance details, vitals logs, and appointment queue.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        <?php else: ?>
            <div style="background: var(--white); border-radius: 16px; padding: 80px 20px; text-align: center; color: var(--text-light); box-shadow: 0 6px 20px rgba(0,0,0,0.05);">
                <p style="font-size: 15px; font-weight: 500;">Type in a patient's name, email address, or contact number to look up their full clinical profile record.</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
