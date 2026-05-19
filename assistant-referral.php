<?php
/**
 * assistant-referral.php
 */

require_once 'config.php';
requireRole(ROLE_ASSISTANT);

$pdo = getPDO();
$user_id = getCurrentUserId();

$message = '';
$message_type = 'success';

// 1. Get patients for dropdown
try {
    $stmt = $pdo->prepare('
        SELECT p.patient_id, u.first_name, u.last_name 
        FROM patient p 
        JOIN users u ON p.user_id = u.user_id 
        ORDER BY u.last_name ASC
    ');
    $stmt->execute();
    $patients = $stmt->fetchAll();
} catch (Exception $e) { $patients = []; }

// 2. Get doctors for dropdown
try {
    $stmt = $pdo->prepare('
        SELECT d.doctor_id, u.first_name, u.last_name, d.specialty
        FROM doctor d 
        JOIN users u ON d.user_id = u.user_id 
        ORDER BY u.last_name ASC
    ');
    $stmt->execute();
    $doctors = $stmt->fetchAll();
} catch (Exception $e) { $doctors = []; }

// 3. Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        if (isset($_POST['create_referral'])) {
            $patient_id = (int)$_POST['patient_id'];
            $specialist_name = sanitize($_POST['specialist_name'] ?? '');
            $clinic_location = sanitize($_POST['clinic_location'] ?? '');
            $referral_notes = sanitize($_POST['notes'] ?? ''); 
            $urgency = sanitize($_POST['urgency'] ?? 'routine');
            $selected_doctor_id = (int)($_POST['doctor_id'] ?? 0);
            $followup_date = $_POST['followup_date'] ?: null;
            $auth_num = sanitize($_POST['authorization_number'] ?? '');
            $ins_info = sanitize($_POST['insurance_info'] ?? '');
            
            if (!$patient_id || !$specialist_name) {
                throw new Exception('Patient and Specialist name are required.');
            }

            // STEP 1: Find latest appointment AND get the doctor_id assigned to it
            $stmt = $pdo->prepare('
                SELECT appointment_id, doctor_id FROM appointment 
                WHERE patient_id = ? 
                ORDER BY appointment_id DESC LIMIT 1
            ');
            $stmt->execute([$patient_id]);
            $apt = $stmt->fetch();
            
            if (!$apt) {
                throw new Exception('No appointment found for this patient.');
            }
            $appointment_id = $apt['appointment_id'];
            $assigned_doctor_id = $apt['doctor_id']; // This is the doctor from the appointment

            // STEP 2: Check for Medical Record, Create Placeholder if missing
            // FIX: Added doctor_id to satisfy fk_medical_doctor
            $stmt = $pdo->prepare('SELECT record_id FROM medical_record WHERE appointment_id = ?');
            $stmt->execute([$appointment_id]);
            $record = $stmt->fetch();

            if ($record) {
                $record_id = $record['record_id'];
            } else {
                $stmt = $pdo->prepare('
                    INSERT INTO medical_record (appointment_id, patient_id, doctor_id, notes, created_at) 
                    VALUES (?, ?, ?, "Referral drafted - clinical documentation pending.", NOW())
                ');
                $stmt->execute([$appointment_id, $patient_id, $assigned_doctor_id]);
                $record_id = $pdo->lastInsertId();
            }
            
            // STEP 3: Insert into the main referral table
            $stmt = $pdo->prepare('
                INSERT INTO referral (record_id, to_whom, reason, status, issued_at) 
                VALUES (?, ?, ?, "issued", NOW())
            ');
            $stmt->execute([$record_id, $specialist_name, $referral_notes]);
            $referral_id = $pdo->lastInsertId();
            
            // STEP 4: Insert into assistant_referral
            $stmt = $pdo->prepare('
                INSERT INTO assistant_referral (
                    appointment_id, referral_id, created_by, doctor_id,
                    specialist_name, clinic_location, notes, status, urgency, 
                    followup_date, authorization_number, insurance_info, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, "draft", ?, ?, ?, ?, NOW())
            ');
            $stmt->execute([
                $appointment_id, 
                $referral_id, 
                $user_id, 
                $selected_doctor_id ?: $assigned_doctor_id, // Use selected or default to appointment doctor
                $specialist_name,
                $clinic_location, 
                $referral_notes, 
                $urgency, 
                $followup_date, 
                $auth_num, 
                $ins_info
            ]);
            
            $pdo->commit();
            $message = '✓ Referral draft created successfully!';
        }

        // Action: Submit Referral
        if (isset($_POST['submit_referral'])) {
            $ar_id = (int)$_POST['referral_id'];
            $stmt = $pdo->prepare('UPDATE assistant_referral SET status = "submitted", submitted_at = NOW() WHERE assistant_referral_id = ?');
            $stmt->execute([$ar_id]);
            $pdo->commit();
            $message = '✓ Referral submitted.';
        }

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $message = '✗ Error: ' . $e->getMessage();
        $message_type = 'error';
    }
}

// 4. Fetch Referrals for Display
$query = "SELECT ar.*, up.first_name as pf, up.last_name as pl 
          FROM assistant_referral ar
          JOIN appointment a ON ar.appointment_id = a.appointment_id
          JOIN patient p ON a.patient_id = p.patient_id
          JOIN users up ON p.user_id = up.user_id
          ORDER BY ar.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute();
$referrals = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Referrals | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary-green: #1a4d34; --accent-green: #2d6a4f; --light-bg: #c5e6e1; --white: #ffffff; --danger: #d90429; }
        body { font-family: 'Quicksand', sans-serif; background: var(--light-bg); color: #1b4332; margin: 0; }
        .top-nav { background: var(--primary-green); padding: 12px 5%; display: flex; justify-content: space-between; align-items: center; color: white; }
        .nav-brand img { height: 40px; filter: brightness(0) invert(1); }
        .nav-links a { color: white; text-decoration: none; margin-left: 15px; font-size: 14px; padding: 8px; border-radius: 5px; }
        .container { max-width: 1000px; margin: 20px auto; padding: 20px; }
        .card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .message { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; text-align: center; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 15px; }
        input, select, textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-family: inherit; }
        .btn-main { background: var(--accent-green); color: white; border: none; padding: 12px; width: 100%; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .referral-item { border-left: 5px solid var(--accent-green); background: #f9f9f9; padding: 15px; margin-bottom: 10px; border-radius: 4px; }
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
            <a href="assistant-appointments.php">📅 Appointments</a>
            <a href="assistant-broadcast.php">📢 Alerts</a>
            <a href="assistant-referral.php" class="active" style="background: var(--accent-green)">📤 Referrals</a>
            <a href="assistant-inventory.php">📦 Supplies</a>
            <a href="assistant-patient-search.php">🔍 Search</a>
        </div>
    </div>

    <div class="container">
        <h1>📤 Manage Referrals</h1>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="card">
            <h3>New Referral Draft</h3>
            <form method="POST">
                <div class="form-grid">
                    <div>
                        <label>Patient</label>
                        <select name="patient_id" required>
                            <option value="">-- Select Patient --</option>
                            <?php foreach ($patients as $p) echo "<option value='{$p['patient_id']}'>{$p['last_name']}, {$p['first_name']}</option>"; ?>
                        </select>
                    </div>
                    <div>
                        <label>Specialist/Clinic</label>
                        <input type="text" name="specialist_name" required>
                    </div>
                    <div>
                        <label>Urgency</label>
                        <select name="urgency">
                            <option value="routine">Routine</option>
                            <option value="urgent">Urgent</option>
                            <option value="emergent">Emergent</option>
                        </select>
                    </div>
                </div>
                <div class="form-grid">
                    <div>
                        <label>Referring Doctor</label>
                        <select name="doctor_id">
                            <option value="">Auto-detect from Appointment</option>
                            <?php foreach ($doctors as $d) echo "<option value='{$d['doctor_id']}'>Dr. {$d['last_name']}</option>"; ?>
                        </select>
                    </div>
                    <div>
                        <label>Clinic Location</label>
                        <input type="text" name="clinic_location">
                    </div>
                    <div>
                        <label>Follow-up Date</label>
                        <input type="date" name="followup_date">
                    </div>
                </div>
                <textarea name="notes" placeholder="Reason for referral..." rows="3"></textarea>
                <button type="submit" name="create_referral" class="btn-main" style="margin-top:10px;">Save Referral</button>
            </form>
        </div>

        <div class="card">
            <h3>Recent History</h3>
            <?php foreach ($referrals as $r): ?>
                <div class="referral-item">
                    <div style="display:flex; justify-content:space-between;">
                        <strong>👤 <?php echo "{$r['pl']}, {$r['pf']}"; ?></strong>
                        <span style="font-size:11px;"><?php echo strtoupper($r['status']); ?></span>
                    </div>
                    <p style="font-size: 13px; margin: 5px 0;">To: <?php echo htmlspecialchars($r['specialist_name']); ?> | Urgency: <?php echo $r['urgency']; ?></p>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>