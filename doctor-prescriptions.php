<?php
/**
 * doctor-prescriptions.php
 * Fully integrated with Prescription & Prescription_Item schema.
 * Features: Auto-Medical Record creation, Transactional Safety, and Mint UI.
 */

require_once 'config.php';
requireRole('doctor');

$pdo = getPDO();
$doctor_id = getCurrentRoleId(); // Ensure this returns the ID from the 'doctor' table

$message = '';
$status_class = '';

// --- 1. HANDLE ADDING NEW MEDICINE TO REGISTRY ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_new_medicine'])) {
    $name = sanitize($_POST['new_med_name'] ?? '');
    if (!empty($name)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO medicine (name) VALUES (?)");
            $stmt->execute([$name]);
            $message = "✓ Added to Registry: $name";
            $status_class = "success";
        } catch (Exception $e) {
            $message = "✗ Error: " . $e->getMessage();
            $status_class = "error";
        }
    }
}

// --- 2. HANDLE NEW PRESCRIPTION SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_prescription'])) {
    
    if (empty($doctor_id)) {
        $doctor_id = 1; 
    }

    $p_id = (int)($_POST['patient_id'] ?? 0);
    $m_id = (int)($_POST['medicine_id'] ?? 0);
    
    if ($p_id > 0 && $m_id > 0) {
        try {
            $pdo->beginTransaction();

            // Step A: Get or Create Medical Record ID
            $stmtRec = $pdo->prepare("SELECT record_id FROM medical_record WHERE patient_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmtRec->execute([$p_id]);
            $record = $stmtRec->fetch();
            $record_id = $record ? $record['record_id'] : null;

            if (!$record_id) {
                /**
                 * FIX: Find the most recent appointment. 
                 * We order by appointment_id DESC as a fallback for the date column.
                 */
                $stmtAppt = $pdo->prepare("SELECT appointment_id FROM appointment WHERE patient_id = ? ORDER BY appointment_id DESC LIMIT 1");
                $stmtAppt->execute([$p_id]);
                $appt = $stmtAppt->fetch();
                $appointment_id = $appt ? $appt['appointment_id'] : null;

                if (!$appointment_id) {
                    throw new Exception("This patient has no appointments. Please create an appointment before issuing a prescription.");
                }

                $stmtNewRec = $pdo->prepare("INSERT INTO medical_record (patient_id, doctor_id, appointment_id) VALUES (?, ?, ?)");
                $stmtNewRec->execute([$p_id, $doctor_id, $appointment_id]);
                $record_id = $pdo->lastInsertId();
            }

            // Step B: Insert into 'prescription'
            $stmtPresc = $pdo->prepare("INSERT INTO prescription (record_id, patient_id, doctor_id, issued_at, notes, status) VALUES (?, ?, ?, NOW(), ?, 'active')");
            $stmtPresc->execute([
                $record_id, 
                $p_id, 
                $doctor_id, 
                sanitize($_POST['general_notes'] ?? '')
            ]);
            $prescription_id = $pdo->lastInsertId();

            // Step C: Insert into 'prescription_item'
            $stmtItem = $pdo->prepare("INSERT INTO prescription_item (prescription_id, medicine_id, dosage, frequency, duration, instructions, quantity_ordered) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtItem->execute([
                $prescription_id,
                $m_id,
                sanitize($_POST['dosage'] ?? ''),
                sanitize($_POST['frequency'] ?? ''),
                sanitize($_POST['duration'] ?? ''),
                sanitize($_POST['instructions'] ?? ''),
                (int)($_POST['quantity_ordered'] ?? 1)
            ]);

            $pdo->commit();
            $message = "✓ Prescription issued successfully!";
            $status_class = "success";
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "✗ Database Error: " . $e->getMessage();
            $status_class = "error";
        }
    } else {
        $message = "⚠ Please select a patient and medicine.";
        $status_class = "error";
    }
}

// --- 3. FETCH DATA FOR THE UI ---
$medicines = $pdo->query("SELECT * FROM medicine ORDER BY name ASC")->fetchAll();
$patients = $pdo->query("SELECT p.patient_id, u.first_name, u.last_name FROM patient p JOIN users u ON p.user_id = u.user_id ORDER BY u.last_name ASC")->fetchAll();

// History List
$history = $pdo->prepare("
    SELECT p.issued_at, u.first_name, u.last_name, m.name as med_name, pi.dosage, p.status 
    FROM prescription p 
    JOIN prescription_item pi ON p.prescription_id = pi.prescription_id 
    JOIN medicine m ON pi.medicine_id = m.med_id 
    JOIN patient pat ON p.patient_id = pat.patient_id 
    JOIN users u ON pat.user_id = u.user_id 
    WHERE p.doctor_id = ? 
    ORDER BY p.issued_at DESC LIMIT 6
");
$history->execute([$doctor_id]);
$history_list = $history->fetchAll();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prescriptions | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --nav-bg: #1a3c34;
            --page-bg: #e0f2f1;
            --card-white: #ffffff;
            --primary: #2d7a6a;
            --accent: #3ba89f;
            --text-dark: #333333;
            --text-muted: #666666;
            --danger: #dc3545;
        }

        body { font-family: 'Quicksand', sans-serif; background: var(--page-bg); margin: 0; color: var(--text-dark); }
        
        /* Navigation */
        .top-nav { background: var(--nav-bg); padding: 12px 40px; display: flex; justify-content: space-between; align-items: center; color: white; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
  
        .nav-brand img { height: 40px; filter: brightness(0) invert(1); }
        .nav-center { display: flex; gap: 12px; }
        .nav-pill { background: rgba(255, 255, 255, 0.1); color: white; text-decoration: none; padding: 6px 18px; border-radius: 20px; font-size: 0.85rem; transition: 0.3s; }
        .nav-pill:hover, .nav-pill.active { background: var(--accent); }
        .logout-btn { background: var(--danger); color: white; padding: 6px 15px; border-radius: 5px; text-decoration: none; font-size: 0.85rem; font-weight: 600; }

        /* Container */
        .container { max-width: 1200px; margin: 40px auto; padding: 0 20px; }
        .welcome-card { background: var(--card-white); padding: 25px; border-radius: 15px; border-left: 6px solid var(--primary); margin-bottom: 30px; }
        .welcome-card h1 { margin: 0; font-size: 1.5rem; color: var(--primary); }

        /* Grid */
        .dashboard-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; }
        .card { background: var(--card-white); border-radius: 15px; padding: 25px; box-shadow: 0 4px 12px rgba(0,0,0,0.04); margin-bottom: 25px; }
        .card h3 { margin-top: 0; color: var(--primary); font-size: 1.1rem; display: flex; align-items: center; gap: 10px; }

        /* Forms */
        .input-group { margin-bottom: 15px; }
        label { display: block; font-size: 0.85rem; font-weight: 600; color: var(--text-muted); margin-bottom: 6px; }
        select, input, textarea { width: 100%; padding: 12px; border: 1.5px solid #eee; border-radius: 10px; box-sizing: border-box; font-family: inherit; }
        input:focus, select:focus { border-color: var(--accent); outline: none; }
        .grid-flex { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .btn-submit { background: var(--primary); color: white; border: none; padding: 14px; border-radius: 10px; font-weight: 700; cursor: pointer; width: 100%; transition: 0.3s; }
        .btn-submit:hover { background: var(--accent); transform: translateY(-2px); }

        /* Alerts */
        .alert { padding: 15px; border-radius: 10px; margin-bottom: 25px; font-weight: 600; font-size: 0.9rem; }
        .success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .error { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }

        /* Tables */
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 0.75rem; color: var(--text-muted); padding: 12px; border-bottom: 1px solid #eee; }
        td { padding: 15px 12px; font-size: 0.85rem; border-bottom: 1px solid #fafafa; }
        .status-badge { background: #e0f2f1; color: var(--primary); padding: 4px 8px; border-radius: 12px; font-weight: 700; font-size: 0.7rem; }
        .med-badge { display: inline-block; padding: 5px 12px; background: #f0f4f3; border-radius: 20px; color: var(--primary); margin: 0 5px 8px 0; font-size: 0.8rem; font-weight: 600; }

        @media (max-width: 900px) { .dashboard-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

    <nav class="top-nav">
        <div class="nav-brand">
            <img src="images/Logo_only.png" alt="Health4Q">
        </div>
        <div class="nav-center">
            <a href="doctor-dashboard.php" class="nav-pill">Home</a>
            <a href="doctor-prescriptions.php" class="nav-pill active">Prescriptions</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </nav>

    <div class="container">
        <div class="welcome-card">
            <h1>Prescription Management</h1>
            <p>System updated: Logged via <code>prescription</code> and <code>prescription_item</code> tables.</p>
        </div>

        <?php if ($message): ?>
            <div class="alert <?= $status_class ?>"><?= $message ?></div>
        <?php endif; ?>

        <div class="dashboard-grid">
            <!-- Main Prescription Logic -->
            <div class="main-col">
                <div class="card">
                    <h3>📝 New Prescription</h3>
                    <form method="POST">
                        <div class="grid-flex">
                            <div class="input-group">
                                <label>Patient</label>
                                <select name="patient_id" required>
                                    <option value="">Select Patient...</option>
                                    <?php foreach ($patients as $p): ?>
                                        <option value="<?= $p['patient_id'] ?>"><?= htmlspecialchars($p['last_name'].", ".$p['first_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="input-group">
                                <label>Medication</label>
                                <select name="medicine_id" required>
                                    <option value="">Select Medicine...</option>
                                    <?php foreach ($medicines as $m): ?>
                                        <option value="<?= $m['med_id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="grid-flex" style="grid-template-columns: 1fr 1fr 100px;">
                            <div class="input-group"><label>Dosage</label><input type="text" name="dosage" placeholder="500mg" required></div>
                            <div class="input-group"><label>Frequency</label><input type="text" name="frequency" placeholder="2x Daily" required></div>
                            <div class="input-group"><label>Qty</label><input type="number" name="quantity_ordered" value="1" min="1"></div>
                        </div>

                        <div class="input-group">
                            <label>Duration (Optional)</label>
                            <input type="text" name="duration" placeholder="e.g., 7 Days">
                        </div>

                        <div class="input-group">
                            <label>General Notes (Header Table)</label>
                            <input type="text" name="general_notes" placeholder="Reasons or clinical context...">
                        </div>

                        <div class="input-group">
                            <label>Patient Instructions (Item Table)</label>
                            <textarea name="instructions" rows="2" placeholder="Take after meals..."></textarea>
                        </div>

                        <button type="submit" name="add_prescription" class="btn-submit">Issue & Save Prescription</button>
                    </form>
                </div>

                <div class="card">
                    <h3>🕒 Recent Activity</h3>
                    <table>
                        <thead>
                            <tr><th>Issued</th><th>Patient</th><th>Medication</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($history_list as $h): ?>
                            <tr>
                                <td><?= date('M d', strtotime($h['issued_at'])) ?></td>
                                <td><strong><?= htmlspecialchars($h['last_name']) ?></strong></td>
                                <td style="color:var(--primary);"><?= htmlspecialchars($h['med_name']) ?> (<?= htmlspecialchars($h['dosage']) ?>)</td>
                                <td><span class="status-badge"><?= strtoupper($h['status']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Sidebar Registry -->
            <div class="side-col">
                <div class="card">
                    <h3>➕ Add Medicine</h3>
                    <form method="POST">
                        <div class="input-group">
                            <label>Name</label>
                            <input type="text" name="new_med_name" required placeholder="Generic/Brand">
                        </div>
                        <button type="submit" name="submit_new_medicine" class="btn-submit" style="background:var(--nav-bg)">Save to Registry</button>
                    </form>
                </div>

                <div class="card">
                    <h3>💊 Available Meds</h3>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <?php foreach ($medicines as $m): ?>
                            <span class="med-badge"><?= htmlspecialchars($m['name']) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

</body>
</html>