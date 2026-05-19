<?php
/**
 * doctor-medical-records.php - PREMIUM PHYSICIAN CLINICAL RECORDS
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
    <title>Clinical Records | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a4d34;
            --primary-light: #2d6a4f;
            --bg-soft: #f4f9f7;
            --white: #ffffff;
            --danger: #d90429;
            --success: #2a9d8f;
            --border: #e0e7e3;
            --text-dark: #1a4d34;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Quicksand', sans-serif; }
        body { background: var(--bg-soft); color: var(--text-dark); min-height: 100vh; }

        /* Unified Navigation Bar */
        .navbar {
            background-color: var(--primary);
            padding: 10px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }
        .nav-brand img { height: 35px; filter: brightness(0) invert(1); }
        .nav-links { display: flex; gap: 10px; }
        .nav-links a {
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            transition: 0.3s;
        }
        .nav-links a:hover, .nav-links a.active { background: rgba(255,255,255,0.1); color: white; }
        .btn-logout { background: var(--danger) !important; color: white !important; font-weight: 700 !important; }

        .container { max-width: 900px; margin: 30px auto; padding: 0 20px; }

        /* Back Navigation */
        .back-link {
            color: var(--primary-light);
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            transition: 0.2s;
        }
        .back-link:hover { color: var(--primary); transform: translateX(-2px); }

        /* Header Info Card */
        .patient-header-card {
            background: var(--white);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            border-left: 6px solid var(--primary);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .patient-meta h1 { font-size: 1.6rem; color: var(--primary); font-weight: 700; }
        .patient-meta p { font-size: 0.9rem; color: #555; font-weight: 600; margin-top: 5px; }

        /* Dynamic Tabs Header */
        .tabs-header { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid rgba(45, 106, 79, 0.15); padding-bottom: 10px; }
        .tab-btn {
            background: none;
            border: none;
            padding: 12px 24px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            color: #555;
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        .tab-btn:hover { background: rgba(45, 106, 79, 0.08); color: var(--primary); }
        .tab-btn.active { background: var(--primary); color: var(--white); }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Alerts */
        .alert { padding: 15px; border-radius: 10px; margin-bottom: 20px; font-weight: 700; text-align: center; }
        .alert-success { background: #b7e4c7; color: #1b4332; border: 1px solid #95d5b2; }
        .alert-error { background: #ffccd5; color: #a4133c; border: 1px solid #ffb3c1; }

        /* Cards */
        .card { background: var(--white); border-radius: 15px; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); border: 1px solid var(--border); }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: 700; margin-bottom: 8px; font-size: 13px; color: var(--primary); }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; outline: none; transition: 0.3s; font-size: 14px; font-weight: 600;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: var(--primary-light); box-shadow: 0 0 0 3px rgba(45, 106, 79, 0.1);
        }

        .btn-submit {
            background: var(--primary);
            color: white;
            border: none;
            padding: 12px;
            width: 100%;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            font-size: 15px;
            transition: 0.2s;
        }
        .btn-submit:hover { background: var(--primary-light); transform: translateY(-1px); }

        /* History items list */
        .search-box { width: 100%; padding: 12px 15px; border-radius: 10px; border: 1px solid var(--border); margin-bottom: 20px; font-weight: 600; outline: none; transition: 0.3s; }
        .search-box:focus { border-color: var(--primary-light); box-shadow: 0 0 0 3px rgba(45, 106, 79, 0.1); }

        .record-item { background: var(--white); padding: 22px; border-radius: 12px; margin-bottom: 18px; border-left: 5px solid var(--primary-light); box-shadow: 0 4px 15px rgba(0,0,0,0.02); }
        .record-meta-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
        .record-date { font-size: 12px; color: #666; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .btn-print { color: var(--primary-light); font-size: 12px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-print:hover { text-decoration: underline; }

        .record-diagnosis { font-size: 1.15rem; font-weight: 700; color: var(--primary); margin-bottom: 8px; }
        .record-treatment { font-size: 14px; color: #333; font-weight: 600; margin-bottom: 10px; }
        .record-note { font-size: 13px; color: #555; font-style: italic; background: var(--bg-soft); padding: 10px 15px; border-radius: 8px; border-left: 3px solid rgba(45, 106, 79, 0.3); }

        @media print {
            .navbar, .tabs-header, .back-link, .search-box, .btn-print, #new-tab, .btn-submit { display: none !important; }
            body { background: white; color: black; }
            .container { max-width: 100%; margin: 0; padding: 0; }
            .record-item { border: 1px solid #ccc; page-break-inside: avoid; margin-bottom: 20px; box-shadow: none; }
        }
    </style>
</head>
<body>

    <nav class="navbar">
        <div class="nav-brand"><img src="images/Logo_only.png" alt="Health4Q"></div>
        <div class="nav-links">
            <a href="doctor-dashboard.php">Dashboard</a>
            <a href="doctor-patient-list.php" class="active">Patients</a>
            <a href="doctor-prescriptions.php">Medicine</a>
            <a href="doctor-profile.php">Profile</a>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </nav>

    <div class="container">
        <a href="doctor-patient-profile.php?patient_id=<?= $patient_id ?>" class="back-link">← Back to Patient Profile</a>

        <div class="patient-header-card">
            <div class="patient-meta">
                <span style="font-size: 11px; font-weight: 800; text-transform: uppercase; color: var(--primary-light); letter-spacing: 1px;">Clinical Record Dossier</span>
                <h1><?= htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']) ?></h1>
                <p>📧 <?= htmlspecialchars($patient['email']) ?></p>
            </div>
            <div style="text-align: right;">
                <span style="background: var(--bg-soft); color: var(--primary); padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; border: 1px solid var(--border);">Active Dossier</span>
            </div>
        </div>

        <?php if ($message): 
            list($type, $text) = explode('|', $message); ?>
            <div class="alert alert-<?= $type ?>"><?= htmlspecialchars($text) ?></div>
        <?php endif; ?>

        <div class="tabs-header">
            <button class="tab-btn active" onclick="switchTab('history')">📋 Clinical History</button>
            <button class="tab-btn" onclick="switchTab('new')">➕ Add New Record</button>
        </div>

        <!-- TAB 1: HISTORY -->
        <div id="history-tab" class="tab-content active">
            <input type="text" id="recordSearch" class="search-box" placeholder="Search diagnoses, plans, or internally logged keywords...">
            
            <div id="historyList">
                <?php if ($records): foreach ($records as $record): ?>
                    <div class="record-item">
                        <div class="record-meta-row">
                            <span class="record-date">📅 <?= date('F d, Y | h:i A', strtotime($record['date_time'])) ?></span>
                            <a href="javascript:window.print()" class="btn-print">🖨️ Print Record</a>
                        </div>
                        <div class="record-diagnosis"><?= htmlspecialchars($record['diagnosis']) ?></div>
                        <?php if ($record['treatment_summary']): ?>
                            <div class="record-treatment"><strong>Plan & Treatment:</strong> <?= htmlspecialchars($record['treatment_summary']) ?></div>
                        <?php endif; ?>
                        <?php if ($record['notes']): ?>
                            <div class="record-note">"<?= htmlspecialchars($record['notes']) ?>"</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; else: ?>
                    <div style="background: white; padding: 40px; text-align: center; border-radius: 12px; border: 1px solid var(--border);">
                        <p style="color: #666; font-size: 15px; font-weight: 600;">No clinical history records recorded for this patient.</p>
                        <small style="color: #999;">Click the "Add New Record" tab to document a new consultation.</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TAB 2: NEW RECORD -->
        <div id="new-tab" class="tab-content">
            <div class="card">
                <form method="POST">
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom: 20px;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Consultation Date & Time</label>
                            <input type="datetime-local" name="date_time" value="<?= date('Y-m-d\TH:i') ?>" required>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Link to Appointment</label>
                            <select name="appointment_id">
                                <option value="">None / Direct Walk-in</option>
                                <?php foreach ($appointments as $apt): ?>
                                    <option value="<?= $apt['appointment_id'] ?>"><?= date('M d, Y @ H:i', strtotime($apt['schedule_start'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Primary Diagnosis *</label>
                        <input type="text" name="diagnosis" placeholder="e.g. Acute Pharyngitis, Essential Hypertension" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Treatment Plan / Summary</label>
                        <textarea name="treatment_summary" rows="4" placeholder="Document prescribed medications, recommended tests, and treatment directions..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Physician Internal Notes (Private Ledger)</label>
                        <textarea name="notes" rows="3" placeholder="Document confidential observations, patient follow-up cues, or special alerts..."></textarea>
                    </div>
                    
                    <button type="submit" name="add_record" class="btn-submit">💾 Finalize & Save Clinical Record</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            
            document.getElementById(tabId + '-tab').classList.add('active');
            
            // Highlight current button
            const activeBtn = Array.from(document.querySelectorAll('.tab-btn')).find(b => 
                b.innerText.includes(tabId === 'history' ? 'History' : 'Add New')
            );
            if (activeBtn) activeBtn.classList.add('active');
        }

        // Live filtration
        document.getElementById('recordSearch').addEventListener('keyup', function() {
            let filter = this.value.toLowerCase();
            document.querySelectorAll('.record-item').forEach(item => {
                item.style.display = item.textContent.toLowerCase().includes(filter) ? "" : "none";
            });
        });
    </script>
</body>
</html>