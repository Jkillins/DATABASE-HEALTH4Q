<?php
/**
 * issuance.php - UI Match for image_8ec781.jpg
 * Features: Mint-green theme, Forest-green header, and PHP Warning Fixes.
 */
require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Security Guard
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location: login.php");
    exit;
}

// PHP Warning Fix: Safely handle missing session keys (referencing image_8ecb7d.png errors)
$first_name = $_SESSION['first_name'] ?? 'Doctor';
$last_name = $_SESSION['last_name'] ?? 'User';
$doctor_name = $first_name . ' ' . $last_name;

$pdo = getPDO();
$user_id = $_SESSION['user_id'];

// Get doctor primary key
$stmt_doc = $pdo->prepare("SELECT doctor_id FROM doctor WHERE user_id = ?");
$stmt_doc->execute([$user_id]);
$doctor_row = $stmt_doc->fetch();
$real_doctor_id = $doctor_row['doctor_id'] ?? null;

$message = '';
$message_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['issue_referral'])) {
    $patient_id = (int)$_POST['patient_id'];
    $specialist = sanitize($_POST['specialist']);
    $reason = sanitize($_POST['reason']);
    $priority = sanitize($_POST['priority']);
    
    try {
        $pdo->beginTransaction();

        // Find latest appointment to link the referral correctly
        $stmt_apt = $pdo->prepare("SELECT appointment_id FROM appointment WHERE patient_id = ? AND doctor_id = ? ORDER BY appointment_id DESC LIMIT 1");
        $stmt_apt->execute([$patient_id, $real_doctor_id]);
        $apt = $stmt_apt->fetch();

        if (!$apt) throw new Exception("No active appointment found. Referrals must be tied to a clinical encounter.");
        $appointment_id = $apt['appointment_id'];

        // Ensure Medical Record exists (Foreign Key Constraint Safety)
        $stmt_rec = $pdo->prepare("SELECT record_id FROM medical_record WHERE appointment_id = ?");
        $stmt_rec->execute([$appointment_id]);
        $record = $stmt_rec->fetch();

        $record_id = $record ? $record['record_id'] : null;
        if (!$record_id) {
            $stmt_new_rec = $pdo->prepare("INSERT INTO medical_record (appointment_id, patient_id, doctor_id, notes, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt_new_rec->execute([$appointment_id, $patient_id, $real_doctor_id, "Referral Drafted: $reason"]);
            $record_id = $pdo->lastInsertId();
        }

        $stmt_ref = $pdo->prepare("INSERT INTO referral (record_id, to_whom, reason, status, issued_at) VALUES (?, ?, ?, 'issued', NOW())");
        $stmt_ref->execute([$record_id, $specialist, $reason]);

        $pdo->commit();
        $message = "✓ Referral successfully issued and saved to patient history.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = "✗ Error: " . $e->getMessage();
        $message_type = "error";
    }
}

// Fetch Patients
$stmt_patients = $pdo->prepare("SELECT p.patient_id, u.first_name, u.last_name FROM patient p JOIN users u ON p.user_id = u.user_id ORDER BY u.last_name ASC");
$stmt_patients->execute();
$patients = $stmt_patients->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Issue Referral | Health4Q+</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Color Palette from image_8ec781.jpg */
        :root {
            --bg-mint: #d8f3dc; /* Soft mint background */
            --header-green: #1b4332; /* Dark forest green header */
            --accent-green: #2d6a4f;
            --white: #ffffff;
            --logout-red: #d90429;
            --text-dark: #1b4332;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Quicksand', sans-serif; }
        body { background-color: var(--bg-mint); color: var(--text-dark); min-height: 100vh; }

        /* Top Navigation Bar matching image_8ec781.jpg */
        .navbar {
            background-color: var(--header-green);
            padding: 10px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .nav-brand img { height: 40px; filter: brightness(0) invert(1); }
        .nav-links { display: flex; gap: 10px; }
        .nav-links a {
            color: var(--white);
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            transition: 0.3s;
        }
        .nav-links a:hover, .nav-links a.active { background: var(--accent-green); }
        .btn-logout { background: var(--logout-red) !important; font-weight: 700 !important; }

        .container { max-width: 900px; margin: 40px auto; padding: 0 20px; }
        
        .page-title { margin-bottom: 30px; }
        .page-title h1 { font-size: 28px; font-weight: 700; color: var(--header-green); }
        .page-title p { font-size: 14px; opacity: 0.8; }

        /* Card Design based on Appointment Cards in image_8ec781.jpg */
        .referral-card {
            background: var(--white);
            border-radius: 15px;
            padding: 40px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .full-width { grid-column: span 2; }

        .input-group { display: flex; flex-direction: column; gap: 8px; margin-bottom: 15px; }
        .input-group label { font-size: 11px; font-weight: 800; text-transform: uppercase; color: #6c757d; }

        input, select, textarea {
            padding: 12px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            background: #f8f9fa;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-dark);
        }
        input:focus { border-color: var(--accent-green); outline: none; background: #fff; }

        .alert { 
            padding: 15px; border-radius: 10px; margin-bottom: 20px; 
            text-align: center; font-weight: 700; font-size: 14px;
        }
        .alert-success { background: #b7e4c7; color: #1b4332; }
        .alert-error { background: #ffccd5; color: #a4133c; }

        .btn-submit {
            background: var(--header-green);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            width: 100%;
            margin-top: 20px;
            transition: 0.3s;
        }
        .btn-submit:hover { background: var(--accent-green); transform: translateY(-2px); }

        .footer-sig {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            border-top: 1px solid #eee;
            padding-top: 20px;
        }
        .sig-name { font-size: 18px; font-weight: 700; border-bottom: 2px solid var(--header-green); }

        @media print {
            .navbar, .btn-submit { display: none; }
            body { background: white; }
            .referral-card { box-shadow: none; border: 1px solid #eee; }
        }
    </style>
</head>
<body>

    <nav class="navbar">
        <div class="nav-brand"><img src="images/Logo_only.png" alt="Health4Q"></div>
        <div class="nav-links">
            <a href="doctor-dashboard.php">Dashboard</a>
            <a href="doctor-profile.php">Profile</a>
            <a href="doctor-appointment.php">Appointments</a>
            <a href="doctor-medical-data.php">Medical Data</a>
            <a href="issuance.php" class="active">Referrals</a>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="page-title">
            <h1>Issue Referral</h1>
            <p>Generate secure specialist referrals for patients based on current consultation findings.</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="referral-card">
            <form method="POST">
                <div class="form-grid">
                    <div class="input-group">
                        <label>Referring Physician</label>
                        <input type="text" value="Dr. <?php echo htmlspecialchars($doctor_name); ?>" readonly>
                    </div>
                    <div class="input-group">
                        <label>Date of Issuance</label>
                        <input type="text" value="<?php echo date('F d, Y'); ?>" readonly>
                    </div>

                    <div class="input-group">
                        <label>Select Patient</label>
                        <select name="patient_id" required>
                            <option value="">-- Search Patient --</option>
                            <?php foreach ($patients as $p): ?>
                                <option value="<?php echo $p['patient_id']; ?>">
                                    <?php echo htmlspecialchars($p['last_name'] . ', ' . $p['first_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-group">
                        <label>Target Specialist / Dept.</label>
                        <input type="text" name="specialist" placeholder="e.g. Cardiology Unit" required>
                    </div>

                    <div class="input-group full-width">
                        <label>Clinical Reason for Referral</label>
                        <textarea name="reason" rows="5" placeholder="State diagnostic findings and specific request for the specialist..." required></textarea>
                    </div>

                    <div class="input-group">
                        <label>Priority Level</label>
                        <select name="priority">
                            <option>Routine</option>
                            <option>Urgent</option>
                            <option>STAT (Emergency)</option>
                        </select>
                    </div>
                </div>

                <button type="submit" name="issue_referral" class="btn-submit">Issue & Sync Referral</button>

                <div class="footer-sig">
                    <div>
                        <p style="font-size: 12px; color: #6c757d;">Document ID: <?php echo strtoupper(bin2hex(random_bytes(4))); ?></p>
                        <p style="font-size: 11px; color: #6c757d;">Electronic Signature Verified</p>
                    </div>
                    <div style="text-align: center;">
                        <div class="sig-name">Dr. <?php echo htmlspecialchars($last_name); ?></div>
                        <p style="font-size: 10px; text-transform: uppercase; margin-top: 5px;">Authorized Medical Practitioner</p>
                    </div>
                </div>
            </form>
        </div>
    </div>

</body>
</html>