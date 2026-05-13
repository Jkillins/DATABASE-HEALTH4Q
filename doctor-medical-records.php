<?php
/**
 * doctor-medical-records.php
 */

require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. Access Control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
$user_id = $_SESSION['user_id']; 
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;

if (!$patient_id) {
    header('Location: doctor-patient-list.php');
    exit;
}

$message = '';

try {
    // 2. CRITICAL FIX: Fetch the actual doctor_id from the doctor table
    $stmtDoc = $pdo->prepare("SELECT doctor_id FROM doctor WHERE user_id = ?");
    $stmtDoc->execute([$user_id]);
    $doctorData = $stmtDoc->fetch();

    if (!$doctorData) {
        die("System Error: Your user account is not correctly linked to a Doctor profile in the database.");
    }
    $doctor_id = $doctorData['doctor_id'];

    // 3. Get patient info
    $stmt = $pdo->prepare('
        SELECT u.first_name, u.last_name, u.email 
        FROM patient p 
        JOIN users u ON p.user_id = u.user_id 
        WHERE p.patient_id = ?
    ');
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch();

    if (!$patient) {
        header('Location: doctor-patient-list.php');
        exit;
    }

    // 4. Handle new record submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record'])) {
        $date_time = $_POST['date_time'] ?? date('Y-m-d H:i');
        $diagnosis = trim($_POST['diagnosis'] ?? '');
        $treatment = trim($_POST['treatment_summary'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $appointment_id = !empty($_POST['appointment_id']) ? (int)$_POST['appointment_id'] : null;

        if (!empty($diagnosis)) {
            $stmtInsert = $pdo->prepare('
                INSERT INTO medical_record (patient_id, appointment_id, doctor_id, date_time, diagnosis, treatment_summary, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmtInsert->execute([$patient_id, $appointment_id, $doctor_id, $date_time, $diagnosis, $treatment, $notes]);
            $message = 'success|Medical record created successfully.';
            
            // Refresh records list after insertion
            header("Location: doctor-medical-records.php?patient_id=$patient_id&msg=success");
            exit;
        } else {
            $message = 'error|Diagnosis is required.';
        }
    }

    // 5. Check for success message from redirect
    if (isset($_GET['msg']) && $_GET['msg'] == 'success') {
        $message = 'success|Medical record created successfully.';
    }

    // 6. Fetch medical history
    $stmtHistory = $pdo->prepare('SELECT * FROM medical_record WHERE patient_id = ? ORDER BY date_time DESC');
    $stmtHistory->execute([$patient_id]);
    $records = $stmtHistory->fetchAll();

    // 7. Get appointments for dropdown
    $stmtApt = $pdo->prepare('SELECT appointment_id, schedule_start FROM appointment WHERE patient_id = ? AND status != "cancelled" ORDER BY schedule_start DESC LIMIT 10');
    $stmtApt->execute([$patient_id]);
    $appointments = $stmtApt->fetchAll();

} catch (Exception $e) {
    $message = 'error|Database Error: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Records | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;600;700&family=Inter:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a4d34;
            --secondary: #2d6a4f;
            --bg: #f0fdf4;
            --white: #ffffff;
            --danger: #ef4444;
            --success: #10b981;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Quicksand', sans-serif; background: var(--bg); color: #1b4332; }

        /* Nav */
        .top-nav { background: var(--primary); padding: 15px 5%; display: flex; justify-content: space-between; align-items: center; }
        .nav-brand img { height: 35px; filter: brightness(0) invert(1); }
        .nav-links a { color: white; text-decoration: none; margin-right: 15px; font-weight: 600; font-size: 14px; }
        .logout-btn { background: var(--danger); padding: 8px 15px; border-radius: 6px; color: white; text-decoration: none; font-size: 12px; font-weight: 700; }

        .container { max-width: 900px; margin: 40px auto; padding: 0 20px; }
        
        /* Header */
        .header-box { margin-bottom: 30px; }
        .back-link { color: var(--secondary); text-decoration: none; font-size: 14px; font-weight: 600; display: block; margin-bottom: 10px; }
        
        /* Tabs */
        .tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; }
        .tab-btn { padding: 10px 20px; border: none; background: none; cursor: pointer; font-weight: 700; color: #64748b; border-bottom: 3px solid transparent; }
        .tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); }

        /* Alerts */
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        /* Cards */
        .card { background: var(--white); border-radius: 15px; padding: 25px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 700; font-size: 14px; }
        .form-group input, .form-group select, .form-group textarea { 
            width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-family: 'Inter', sans-serif;
        }
        .btn-submit { background: var(--primary); color: white; border: none; padding: 12px; width: 100%; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s; }
        .btn-submit:hover { background: var(--secondary); }

        /* History */
        .search-input { width: 100%; padding: 10px 15px; border-radius: 10px; border: 1px solid #ddd; margin-bottom: 20px; }
        .record-item { background: white; padding: 20px; border-radius: 12px; margin-bottom: 15px; border-left: 4px solid var(--primary); }
        .record-date { font-size: 12px; color: #64748b; font-weight: 700; }
        .record-diagnosis { font-size: 16px; margin: 5px 0; font-weight: 700; }
        .record-treatment { font-size: 14px; color: #334155; margin-bottom: 10px; }
        .record-note { font-size: 13px; color: #64748b; font-style: italic; background: #f8fafc; padding: 8px; border-radius: 4px; }

        @media print {
            .top-nav, .tabs, .form-section, .search-input, .back-link, .btn-submit { display: none !important; }
            .container { max-width: 100%; margin: 0; }
            .record-item { border: 1px solid #ddd; page-break-inside: avoid; }
        }
    </style>
</head>
<body>

<nav class="top-nav">
    <div class="nav-brand"><img src="images/Logo_only.png" alt="Health4Q"></div>
    <div class="nav-links">
        <a href="doctor-dashboard.php">Dashboard</a>
        <a href="doctor-patient-list.php">Patients</a>
    </div>
    <a href="logout.php" class="logout-btn">Logout</a>
</nav>

<div class="container">
    <div class="header-box">
        <a href="doctor-patient-profile.php?patient_id=<?= $patient_id ?>" class="back-link">← Back to Profile</a>
        <h1>Medical Records</h1>
        <p>Patient: <strong><?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?></strong></p>
    </div>

    <?php if ($message): 
        list($type, $text) = explode('|', $message); ?>
        <div class="alert alert-<?= $type ?>"><?= htmlspecialchars($text) ?></div>
    <?php endif; ?>

    <div class="tabs">
        <button class="tab-btn active" onclick="switchTab('history')">Clinical History</button>
        <button class="tab-btn" onclick="switchTab('new')">+ New Record</button>
    </div>

    <!-- History Tab -->
    <div id="history-tab" class="tab-content">
        <input type="text" id="recordSearch" class="search-input" placeholder="Search diagnosis or treatment keywords...">
        <div id="historyList">
            <?php if ($records): foreach ($records as $record): ?>
                <div class="record-item">
                    <div style="display:flex; justify-content:space-between;">
                        <span class="record-date">📅 <?= date('M d, Y | h:i A', strtotime($record['date_time'])) ?></span>
                        <a href="javascript:window.print()" style="font-size:11px; color:var(--primary);">Print Record</a>
                    </div>
                    <div class="record-diagnosis"><?= htmlspecialchars($record['diagnosis']) ?></div>
                    <?php if ($record['treatment_summary']): ?>
                        <div class="record-treatment"><strong>Plan:</strong> <?= htmlspecialchars($record['treatment_summary']) ?></div>
                    <?php endif; ?>
                    <?php if ($record['notes']): ?>
                        <div class="record-note">"<?= htmlspecialchars($record['notes']) ?>"</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; else: ?>
                <p style="text-align:center; padding:40px; color:#64748b;">No clinical records found.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- New Entry Tab -->
    <div id="new-tab" class="tab-content" style="display:none;">
        <div class="card">
            <form method="POST">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:15px;">
                    <div class="form-group">
                        <label>Date & Time</label>
                        <input type="datetime-local" name="date_time" value="<?= date('Y-m-d\TH:i') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Related Appointment</label>
                        <select name="appointment_id">
                            <option value="">None / Walk-in</option>
                            <?php foreach ($appointments as $apt): ?>
                                <option value="<?= $apt['appointment_id'] ?>"><?= date('M d, Y @ H:i', strtotime($apt['schedule_start'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Diagnosis *</label>
                    <input type="text" name="diagnosis" placeholder="Enter primary diagnosis..." required>
                </div>
                <div class="form-group">
                    <label>Treatment Summary</label>
                    <textarea name="treatment_summary" rows="3" placeholder="Medications, rest, or procedures..."></textarea>
                </div>
                <div class="form-group">
                    <label>Physician Notes (Internal)</label>
                    <textarea name="notes" rows="2" placeholder="Confidential clinical notes..."></textarea>
                </div>
                <button type="submit" name="add_record" class="btn-submit">Save Clinical Record</button>
            </form>
        </div>
    </div>
</div>

<script>
    function switchTab(tab) {
        document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.getElementById(tab + '-tab').style.display = 'block';
        event.currentTarget.classList.add('active');
    }

    document.getElementById('recordSearch').addEventListener('keyup', function() {
        let filter = this.value.toLowerCase();
        document.querySelectorAll('.record-item').forEach(item => {
            item.style.display = item.textContent.toLowerCase().includes(filter) ? "" : "none";
        });
    });
</script>
</body>
</html>