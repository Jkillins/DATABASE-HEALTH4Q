<?php
/**
 * assistant-patient-search.php
 */

require_once 'config.php';
requireRole(ROLE_ASSISTANT);

$pdo = getPDO();

$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

$patients = [];
$selected_patient = null;
$patient_history = [];

// Search for patients
if ($search) {
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
}

// Get selected patient details
if ($patient_id) {
    $stmt = $pdo->prepare('
        SELECT u.*, p.* FROM patient p
        JOIN users u ON p.user_id = u.user_id
        WHERE p.patient_id = ?
    ');
    $stmt->execute([$patient_id]);
    $selected_patient = $stmt->fetch();

    if ($selected_patient) {
        // Get medical records
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
    <title>Patient Search | Health4Q</title>
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
        .nav-links { display: flex; gap: 12px; }
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
            max-width: 1200px;
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
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .search-form {
            display: flex;
            gap: 10px;
        }

        .search-form input {
            flex: 1;
            padding: 12px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-family: 'Quicksand', sans-serif;
            font-size: 14px;
        }

        .search-form input:focus {
            outline: none;
            border-color: var(--accent-green);
        }

        .search-btn {
            background: var(--accent-green);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.3s;
        }

        .search-btn:hover { background: var(--primary-green); }

        .results-grid {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 25px;
        }

        .patients-list {
            background: var(--white);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            max-height: 600px;
            overflow-y: auto;
        }

        .patient-item {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            cursor: pointer;
            transition: 0.2s;
        }

        .patient-item:hover { background: var(--light-bg); }
        .patient-item.selected { background: var(--light-bg); border-left: 4px solid var(--accent-green); }

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

        .patient-details {
            background: var(--white);
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .details-header {
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .details-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary-green);
        }

        .detail-group {
            margin-bottom: 15px;
        }

        .detail-label {
            font-weight: 600;
            color: var(--primary-green);
            font-size: 12px;
            margin-bottom: 5px;
        }

        .detail-value {
            font-size: 13px;
            color: var(--text-dark);
            padding: 8px;
            background: var(--light-bg);
            border-radius: 6px;
        }

        .history-section {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 2px solid var(--border-color);
        }

        .history-title {
            font-weight: 700;
            color: var(--primary-green);
            font-size: 14px;
            margin-bottom: 15px;
        }

        .history-item {
            background: var(--light-bg);
            padding: 12px;
            border-radius: 8px;
            border-left: 4px solid var(--accent-green);
            margin-bottom: 10px;
        }

        .history-date {
            font-weight: 700;
            color: var(--primary-green);
            font-size: 12px;
            margin-bottom: 5px;
        }

        .history-diagnosis {
            font-size: 12px;
            color: var(--text-dark);
            margin-bottom: 5px;
        }

        .history-doctor {
            font-size: 11px;
            color: var(--text-light);
        }

        .empty-state {
            padding: 40px;
            text-align: center;
            color: var(--text-light);
        }

        @media (max-width: 968px) {
            .results-grid { grid-template-columns: 1fr; }
            .patients-list { max-height: none; }
        }
    </style>
</head>
<body>
    <div class="top-nav">
        <div class="nav-brand">
            <img src="images/Logo_only.png" alt="Health4Q">
        </div>
        <div class="nav-links">
            <a href="assistant-dashboard.php">Overview</a>
            <a href="assistant-queue.php">📋 Live Queue</a>
            <a href="assistant-broadcast.php">📢 Alerts</a>
            <a href="assistant-referral.php">📤 Referrals</a>
            <a href="assistant-patient-search.php" class="active">🔍 Search</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>

    <div class="container">
        <h1>🔍 Search Patient History</h1>

        <!-- Search Section -->
        <div class="search-section">
            <form method="GET" class="search-form">
                <input type="text" name="search" placeholder="Search by name, email, or phone..." value="<?php echo htmlspecialchars($search); ?>">
                <button type="submit" class="search-btn">🔍 Search</button>
            </form>
        </div>

        <!-- Results -->
        <?php if ($search || $patient_id): ?>
            <div class="results-grid">
                <!-- Patients List -->
                <div class="patients-list">
                    <?php if (count($patients) > 0): ?>
                        <?php foreach ($patients as $p): ?>
                            <div class="patient-item <?php echo $p['patient_id'] === $patient_id ? 'selected' : ''; ?>">
                                <a href="?search=<?php echo urlencode($search); ?>&patient_id=<?php echo $p['patient_id']; ?>" style="text-decoration: none; color: inherit;">
                                    <div class="patient-name"><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></div>
                                    <div class="patient-email"><?php echo htmlspecialchars($p['email']); ?></div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <p>No patients found.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Patient Details -->
                <div>
                    <?php if ($selected_patient): ?>
                        <div class="patient-details">
                            <div class="details-header">
                                <div class="details-title">
                                    👤 <?php echo htmlspecialchars($selected_patient['first_name'] . ' ' . $selected_patient['last_name']); ?>
                                </div>
                            </div>

                            <div class="detail-group">
                                <div class="detail-label">Email</div>
                                <div class="detail-value"><?php echo htmlspecialchars($selected_patient['email']); ?></div>
                            </div>

                            <div class="detail-group">
                                <div class="detail-label">Contact</div>
                                <div class="detail-value"><?php echo $selected_patient['contact_no'] ? htmlspecialchars($selected_patient['contact_no']) : 'N/A'; ?></div>
                            </div>

                            <div class="detail-group">
                                <div class="detail-label">Age / Gender</div>
                                <div class="detail-value"><?php echo calculateAge($selected_patient['date_of_birth']); ?> years / <?php echo ucfirst($selected_patient['sex'] ?? 'N/A'); ?></div>
                            </div>

                            <div class="detail-group">
                                <div class="detail-label">Date of Birth</div>
                                <div class="detail-value"><?php echo $selected_patient['date_of_birth'] ? date('M d, Y', strtotime($selected_patient['date_of_birth'])) : 'N/A'; ?></div>
                            </div>

                            <!-- Medical History -->
                            <div class="history-section">
                                <div class="history-title">📋 Medical History</div>
                                <?php if (count($patient_history) > 0): ?>
                                    <?php foreach ($patient_history as $record): ?>
                                        <div class="history-item">
                                            <div class="history-date">📅 <?php echo date('M d, Y H:i', strtotime($record['date_time'])); ?></div>
                                            <div class="history-diagnosis">
                                                <strong>Diagnosis:</strong> <?php echo htmlspecialchars($record['diagnosis'] ?? 'N/A'); ?>
                                            </div>
                                            <?php if ($record['treatment_summary']): ?>
                                                <div class="history-diagnosis">
                                                    <strong>Treatment:</strong> <?php echo htmlspecialchars($record['treatment_summary']); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="history-doctor">
                                                Dr. <?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?> (<?php echo htmlspecialchars($record['specialty']); ?>)
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="padding: 20px; text-align: center; color: var(--text-light);">
                                        No medical records found.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="patient-details">
                            <div class="empty-state">
                                <p>Select a patient from the list to view details.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div style="background: var(--white); border-radius: 12px; padding: 50px 20px; text-align: center; color: var(--text-light); box-shadow: 0 4px 12px rgba(0,0,0,0.08);">
                <p>Enter a patient name or email to search their medical history.</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
