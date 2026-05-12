<?php
/**
 * issuance.php - Official Medical Referral
 * Enhanced with Database Integration & Patient Search
 */
require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
$doctor_id = $_SESSION['user_id'];
$doctor_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];

$message = '';

// 1. Handle Referral Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['issue_referral'])) {
    $patient_id = (int)$_POST['patient_id'];
    $specialist = htmlspecialchars($_POST['specialist']);
    $reason = htmlspecialchars($_POST['reason']);
    $priority = htmlspecialchars($_POST['priority']);
    $clinic = htmlspecialchars($_POST['clinic']);

    try {
        $stmt = $pdo->prepare("INSERT INTO referrals (doctor_id, patient_id, specialist, reason, priority, clinic_location, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$doctor_id, $patient_id, $specialist, $reason, $priority, $clinic]);
        $message = "Referral issued and saved to patient history!";
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
    }
}

// 2. Fetch Patients for the Dropdown
$stmt_patients = $pdo->prepare("SELECT p.patient_id, u.first_name, u.last_name FROM patient p JOIN users u ON p.user_id = u.user_id ORDER BY u.last_name ASC");
$stmt_patients->execute();
$patients = $stmt_patients->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Issue Referral | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&family=Dancing+Script:wght@700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-dark: #1a4d34;
            --primary: #2d6a4f;
            --bg-light: #f1f5f9;
            --white: #ffffff;
            --border: #e2e8f0;
            --mint: #d8f3dc;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Quicksand', sans-serif; }
        body { background: radial-gradient(circle at center, var(--mint) 0%, #c5e6e1 100%); display: flex; color: #1e293b; min-height: 100vh; }

        /* SIDEBAR */
        .sidebar {
            width: 260px; background: var(--white); border-right: 1px solid var(--border);
            padding: 30px 20px; display: flex; flex-direction: column; position: fixed; height: 100vh;
        }
        .nav-label { font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase; margin: 20px 0 10px 10px; }
        .nav-link {
            display: flex; align-items: center; padding: 12px 15px; text-decoration: none;
            color: #64748b; border-radius: 12px; margin-bottom: 4px; font-weight: 600; transition: 0.3s;
        }
        .nav-link:hover, .nav-link.active { background: #ecfdf5; color: var(--primary-dark); }

        /* MAIN CONTENT */
        .main-content { margin-left: 260px; width: calc(100% - 260px); padding: 40px; }

        /* REFERRAL FORM STYLING */
        .referral-container {
            max-width: 850px; margin: 0 auto; background: white; border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1); overflow: hidden; position: relative;
        }
        
        /* Security Watermark Effect */
        .referral-container::after {
            content: "HEALTH4Q VERIFIED";
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 60px; font-weight: 900; color: rgba(0,0,0,0.02); pointer-events: none;
        }

        .doc-header { 
            background: var(--primary-dark); color: white; padding: 40px; text-align: center;
        }
        .doc-body { padding: 40px 60px; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; }
        .full-width { grid-column: span 2; }
        
        .form-group { display: flex; flex-direction: column; gap: 8px; }
        label { font-size: 11px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; }
        
        input, select, textarea {
            padding: 12px 16px; border-radius: 10px; border: 2px solid #f1f5f9;
            background: #f8fafc; font-size: 14px; font-weight: 600; transition: 0.3s;
        }
        input:focus, textarea:focus, select:focus { border-color: var(--primary); outline: none; background: white; }

        /* STATUS BADGE */
        .alert { background: #dcfce7; color: #166534; padding: 15px; border-radius: 12px; margin-bottom: 20px; text-align: center; font-weight: 700; }

        /* SIGNATURE AREA */
        .signature-box {
            margin-top: 40px; padding-top: 30px; border-top: 2px solid #f1f5f9;
            display: flex; justify-content: space-between; align-items: flex-end;
        }
        .sig-line { text-align: center; width: 250px; }
        .doctor-sig { font-family: 'Dancing Script', cursive; font-size: 26px; color: #1e3a8a; border-bottom: 2px solid var(--primary-dark); margin-bottom: 5px; }

        .btn-area { display: flex; gap: 15px; justify-content: center; margin-top: 30px; padding-bottom: 50px; }
        .btn { padding: 14px 35px; border-radius: 12px; font-weight: 700; cursor: pointer; border: none; transition: 0.3s; }
        .btn-submit { background: var(--primary-dark); color: white; box-shadow: 0 4px 15px rgba(26, 77, 52, 0.3); }
        .btn-submit:hover { transform: translateY(-2px); background: var(--primary); }
        .btn-print { background: #64748b; color: white; }

        @media print {
            .sidebar, .btn-area, .nav-label { display: none !important; }
            .main-content { margin: 0; width: 100%; padding: 0; }
            .referral-container { box-shadow: none; border: 1px solid #eee; }
            body { background: white; }
        }
    </style>
</head>
<body>

    <aside class="sidebar">
        <h2 style="color: var(--primary-dark); margin-bottom: 40px; text-align:center;">Health4Q<span style="color:var(--primary);">+</span></h2>
        
        <span class="nav-label">Medical Records</span>
        <a href="doctor-dashboard.php" class="nav-link">📊 Dashboard</a>
        <a href="doctor-appointment.php" class="nav-link">📅 Appointments</a>
        <a href="doctor-medical-data.php" class="nav-link">📁 Patient Files</a>
        <a href="issuance.php" class="nav-link active">📝 Issue Referral</a>
        
        <span class="nav-label">Settings</span>
        <a href="doctor-profile.php" class="nav-link">👤 My Profile</a>
        <a href="logout.php" class="nav-link" style="margin-top:auto; color: #ef4444;">🚪 Logout</a>
    </aside>

    <main class="main-content">
        <?php if ($message): ?>
            <div class="alert"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="referral-container">
            <header class="doc-header">
                <h1 style="letter-spacing: 3px; font-weight: 800; font-size: 22px;">OFFICIAL REFERRAL</h1>
                <p style="opacity: 0.8; font-size: 12px; margin-top: 8px;">DIGITALLY VERIFIED CLINICAL DOCUMENT</p>
            </header>

            <form class="doc-body" method="POST" action="">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Referring Physician</label>
                        <input type="text" value="Dr. <?php echo htmlspecialchars($doctor_name); ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Ref. ID / Timestamp</label>
                        <input type="text" value="REF-<?php echo date('Ymd-His'); ?>" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label>Select Patient</label>
                        <select name="patient_id" required>
                            <option value="">-- Choose Patient --</option>
                            <?php foreach ($patients as $p): ?>
                                <option value="<?php echo $p['patient_id']; ?>">
                                    <?php echo htmlspecialchars($p['last_name'] . ', ' . $p['first_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Target Specialist / Dept</label>
                        <input type="text" name="specialist" placeholder="e.g. Cardiology Unit" required>
                    </div>

                    <div class="form-group full-width">
                        <label>Medical Justification</label>
                        <textarea name="reason" placeholder="Briefly state clinical findings and reason for referral..." rows="4" required></textarea>
                    </div>

                    <div class="form-group">
                        <label>Priority Level</label>
                        <select name="priority">
                            <option value="Routine">Routine (Standard)</option>
                            <option value="Urgent">Urgent (48-hour target)</option>
                            <option value="STAT">STAT (Emergency)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Clinic Location</label>
                        <input type="text" name="clinic" placeholder="Health4Q Branch Name">
                    </div>
                </div>

                <div class="signature-box">
                    <div>
                        <p style="font-size: 11px; color: #94a3b8; margin-bottom: 30px;">This document is generated via secure physician credentials.</p>
                        <p style="font-size: 13px; font-weight: 800; color: var(--primary-dark);">Date: <?php echo date('F d, Y'); ?></p>
                    </div>
                    <div class="sig-line">
                        <div class="doctor-sig">Dr. <?php echo htmlspecialchars($_SESSION['last_name']); ?></div>
                        <label>Authorized Signature</label>
                    </div>
                </div>

                <input type="hidden" name="issue_referral" value="1">
            </form>
        </div>

        <div class="btn-area">
            <button class="btn btn-print" onclick="window.print()">🖨️ Print to PDF</button>
            <button class="btn btn-submit" onclick="document.querySelector('form').submit()">💾 Issue & Save Referral</button>
        </div>
    </main>

</body>
</html>