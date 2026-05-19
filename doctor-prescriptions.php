<?php
/**
 * doctor-prescriptions.php - PREMIUM CLINICAL PRESCRIPTIONS MANAGEMENT
 */

require_once 'config.php';
requireRole('doctor');

$pdo = getPDO();
$doctor_id = getCurrentRoleId();
$user_id = $_SESSION['user_id'] ?? 0;
if (!$doctor_id && $user_id) {
    $stmt_doc = $pdo->prepare("SELECT doctor_id FROM doctor WHERE user_id = ?");
    $stmt_doc->execute([$user_id]);
    $doctor_id = $stmt_doc->fetchColumn() ?: null;
    if ($doctor_id) {
        $_SESSION['role_id'] = (int)$doctor_id;
    }
}

$message = '';
$status_class = '';

// --- 1. HANDLE NEW PRESCRIPTION SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_prescription'])) {

    $p_id = (int)($_POST['patient_id'] ?? 0);
    $m_id = (int)($_POST['medicine_id'] ?? 0); // Links to medicine.med_id
    
    if ($p_id > 0 && $m_id > 0) {
        try {
            // Detect and Validate Stock Availability from medicine table
            $stmtStock = $pdo->prepare("SELECT name, strength, form, stock_quantity FROM medicine WHERE med_id = ?");
            $stmtStock->execute([$m_id]);
            $item = $stmtStock->fetch(PDO::FETCH_ASSOC);
            
            if (!$item) {
                throw new Exception("Selected medicine item does not exist.");
            }
            
            $current_stock = (int)$item['stock_quantity'];
            $qty_ordered = (int)($_POST['quantity_ordered'] ?? 1);

            if ($qty_ordered > $current_stock) {
                throw new Exception("Insufficient stock! Only $current_stock units of '{$item['name']} {$item['strength']} ({$item['form']})' are available in the medicine registry.");
            }

            $pdo->beginTransaction();

            // Step A: Get or Create Medical Record ID
            $stmtRec = $pdo->prepare("SELECT record_id FROM medical_record WHERE patient_id = ? ORDER BY created_at DESC LIMIT 1");
            $stmtRec->execute([$p_id]);
            $record = $stmtRec->fetch();
            $record_id = $record ? $record['record_id'] : null;

            if (!$record_id) {
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
                $qty_ordered
            ]);

            // Step D: Deduct from medicine stock level
            $stmtDeduct = $pdo->prepare("UPDATE medicine SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE med_id = ?");
            $stmtDeduct->execute([$qty_ordered, $m_id]);

            $pdo->commit();

            // Trigger Notification to Patient
            $stmtUser = $pdo->prepare("SELECT user_id FROM patient WHERE patient_id = ?");
            $stmtUser->execute([$p_id]);
            $p_user_id = $stmtUser->fetchColumn();
            if ($p_user_id) {
                createNotification(
                    $p_user_id,
                    "New Prescription Issued",
                    "Dr. " . getCurrentUserFullName() . " has issued a new prescription for you. View details in your Prescriptions portal."
                );
            }

            $message = "✓ Prescription issued successfully! Stock quantity has been updated in the medicine registry.";
            $status_class = "success";
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = "✗ Database Error: " . $e->getMessage();
            $status_class = "error";
        }
    } else {
        $message = "⚠ Please select a patient and medicine item.";
        $status_class = "error";
    }
}

// --- 2. FETCH DATA FOR THE UI ---
$medicines = $pdo->query("SELECT * FROM medicine ORDER BY name ASC")->fetchAll();
$patients = $pdo->query("SELECT p.patient_id, u.first_name, u.last_name FROM patient p JOIN users u ON p.user_id = u.user_id ORDER BY u.last_name ASC")->fetchAll();

// History List joining with medicine table
$history = $pdo->prepare("
    SELECT p.issued_at, u.first_name, u.last_name, m.name as med_name, m.strength, m.form, pi.dosage, p.status 
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
    <title>Prescription Management | Health4Q+</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Forest Green Premium Colors */
        :root {
            --bg-mint: #d8f3dc;
            --header-green: #1b4332;
            --accent-green: #2d6a4f;
            --white: #ffffff;
            --logout-red: #d90429;
            --text-dark: #1b4332;
            --light-mint: #e8f5e9;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Quicksand', sans-serif; background-color: var(--bg-mint); color: var(--text-dark); min-height: 100vh; }
        
        /* Premium Navigation Bar */
        .navbar {
            background-color: var(--header-green);
            padding: 10px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
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

        /* Container & Welcome Card */
        .container { max-width: 1100px; margin: 40px auto; padding: 0 20px; }
        .welcome-card { background: var(--white); padding: 30px; border-radius: 15px; border-left: 6px solid var(--header-green); margin-bottom: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); }
        .welcome-card h1 { margin: 0 0 5px 0; font-size: 1.8rem; color: var(--header-green); font-weight: 700; }
        .welcome-card p { font-size: 14px; opacity: 0.8; }

        /* Stylized Tabs Selection Menu */
        .tabs-container {
            display: flex;
            gap: 12px;
            margin-bottom: 25px;
            background: rgba(255, 255, 255, 0.7);
            padding: 6px;
            border-radius: 12px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.4);
            box-shadow: 0 4px 15px rgba(0,0,0,0.02);
        }
        .tab-btn {
            flex: 1;
            padding: 14px 20px;
            border: none;
            background: transparent;
            color: var(--header-green);
            font-size: 0.95rem;
            font-weight: 700;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .tab-btn:hover {
            background: rgba(27, 67, 50, 0.05);
        }
        .tab-btn.active {
            background: var(--header-green);
            color: var(--white);
            box-shadow: 0 4px 12px rgba(27, 67, 50, 0.15);
        }

        /* Tab Content Display States */
        .tab-content {
            display: none;
            animation: fadeIn 0.4s ease;
        }
        .tab-content.active {
            display: block;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Premium Form Cards & Layouts */
        .card { background: var(--white); border-radius: 15px; padding: 30px; box-shadow: 0 4px 15px rgba(0,0,0,0.03); margin-bottom: 25px; }
        .card h3 { margin-top: 0; color: var(--header-green); font-size: 1.2rem; margin-bottom: 20px; font-weight: 700; display: flex; align-items: center; gap: 8px; border-bottom: 2px solid var(--light-mint); padding-bottom: 10px; }

        /* Custom Inputs & Fields */
        .input-group { margin-bottom: 20px; }
        label { display: block; font-size: 11px; font-weight: 800; text-transform: uppercase; color: #6c757d; margin-bottom: 8px; }
        select, input, textarea { width: 100%; padding: 12px; border: 1.5px solid #dee2e6; border-radius: 10px; box-sizing: border-box; font-family: inherit; font-size: 14px; font-weight: 600; color: var(--text-dark); background: #f8f9fa; transition: 0.3s; }
        input:focus, select:focus, textarea:focus { border-color: var(--accent-green); outline: none; background: #fff; box-shadow: 0 0 8px rgba(45, 106, 79, 0.15); }
        .grid-flex { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .btn-submit { background: var(--header-green); color: white; border: none; padding: 14px; border-radius: 10px; font-weight: 700; cursor: pointer; width: 100%; transition: 0.3s; }
        .btn-submit:hover { background: var(--accent-green); transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.1); }

        /* Alerts & Status Notification */
        .alert { padding: 15px; border-radius: 10px; margin-bottom: 25px; font-weight: 700; font-size: 0.9rem; text-align: center; }
        .success { background: #b7e4c7; color: #1b4332; border: 1px solid #95d5b2; }
        .error { background: #ffccd5; color: #a4133c; border: 1px solid #ffb3c1; }

        /* History Table & List Styles */
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 11px; font-weight: 800; text-transform: uppercase; color: #6c757d; padding: 12px; border-bottom: 1px solid #dee2e6; }
        td { padding: 15px 12px; font-size: 14px; font-weight: 600; border-bottom: 1px solid #f1f3f5; }
        .status-badge { background: var(--light-mint); color: var(--accent-green); padding: 4px 8px; border-radius: 12px; font-weight: 700; font-size: 0.7rem; display: inline-block; }

        @media (max-width: 768px) {
            .grid-flex { grid-template-columns: 1fr; }
            .tabs-container { flex-direction: column; }
        }
    </style>
</head>
<body>

    <nav class="navbar">
        <div class="nav-brand"><img src="images/Logo_only.png" alt="Health4Q"></div>
        <div class="nav-links">
            <a href="doctor-dashboard.php">🏠 Dashboard</a>
            <a href="doctor-patient-list.php">👥 Patients</a>
            <a href="doctor-appointment.php">📅 Appointments</a>
            <a href="doctor-medical-request.php">📁 Requests</a>
            <a href="doctor-prescriptions.php" class="active">💊 Medicine</a>
            <a href="doctor-availability.php">⏰ Availability</a>
            <a href="doctor-profile.php">⚙️ Profile</a>
        </div>
    </nav>

    <div class="container">
        <div class="welcome-card">
            <h1>Prescription Management Portal</h1>
            <p>Issue electronic prescriptions directly connected with medicine registry stocks dynamically.</p>
        </div>

        <?php if ($message): ?>
            <div class="alert <?= $status_class ?>"><?= $message ?></div>
        <?php endif; ?>

        <!-- Styled Tabs Selection Menu -->
        <div class="tabs-container">
            <button class="tab-btn active" id="btn-issue" onclick="switchTab('issue-prescription')">📝 Issue Prescription</button>
            <button class="tab-btn" id="btn-history" onclick="switchTab('prescription-history')">🕒 Recent Activity</button>
        </div>

        <!-- TAB 1: ISSUE PRESCRIPTION -->
        <div id="issue-prescription" class="tab-content active">
            <div class="card">
                <h3>📝 Issue New Prescription</h3>
                <form method="POST">
                    <div class="grid-flex">
                        <div class="input-group">
                            <label>Patient *</label>
                            <select name="patient_id" required>
                                <option value="" disabled selected>-- Select Patient --</option>
                                <?php foreach ($patients as $p): ?>
                                    <option value="<?= $p['patient_id'] ?>"><?= htmlspecialchars($p['last_name'].", ".$p['first_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="input-group">
                            <label>Medication / Supply Item *</label>
                            <select name="medicine_id" id="medSelect" onchange="updateStockDisplay(); validatePrescriptionQuantity();" required>
                                <option value="" disabled selected>-- Select from Medicine Registry --</option>
                                <?php foreach ($medicines as $m): ?>
                                    <?php $stock = (int)($m['stock_quantity'] ?? 0); ?>
                                    <option value="<?= $m['med_id'] ?>" data-stock="<?= $stock ?>">
                                        <?= htmlspecialchars($m['name'] . " " . $m['strength'] . " (" . $m['form'] . ")") ?> [Stock: <?= $stock ?> units]
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small id="stockDisplay" style="color: #6b7280; font-size: 11px; font-weight: 700; display: block; margin-top: 5px;"></small>
                        </div>
                    </div>

                    <div class="grid-flex" style="grid-template-columns: 1fr 1fr 100px;">
                        <div class="input-group">
                            <label>Dosage *</label>
                            <input type="text" name="dosage" placeholder="e.g., 500mg or 5ml" required>
                        </div>
                        <div class="input-group">
                            <label>Frequency *</label>
                            <input type="text" name="frequency" placeholder="e.g., 2x Daily or Every 8 Hours" required>
                        </div>
                        <div class="input-group">
                            <label>Qty *</label>
                            <input type="number" name="quantity_ordered" value="1" min="1" oninput="validatePrescriptionQuantity()" required>
                        </div>
                    </div>

                    <div class="input-group">
                        <label>Duration (Optional)</label>
                        <input type="text" name="duration" placeholder="e.g., 7 Days, 2 Weeks">
                    </div>

                    <div class="input-group">
                        <label>General Clinical Notes (Header Context)</label>
                        <input type="text" name="general_notes" placeholder="e.g. Treat symptoms of acute bronchitis">
                    </div>

                    <div class="input-group">
                        <label>Patient Instructions (Usage directions)</label>
                        <textarea name="instructions" rows="3" placeholder="e.g. Take one tablet twice daily after meals with water. Complete the entire course."></textarea>
                    </div>

                    <button type="submit" name="add_prescription" class="btn-submit">Issue & Save Prescription</button>
                </form>
            </div>
        </div>

        <!-- TAB 2: PRESCRIPTION HISTORY -->
        <div id="prescription-history" class="tab-content">
            <div class="card">
                <h3>🕒 Recent Prescriptions Issued</h3>
                <?php if (empty($history_list)): ?>
                    <p style="text-align: center; padding: 20px; font-style: italic; color: #6c757d;">No prescriptions recorded for your license.</p>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date Issued</th>
                                    <th>Patient Name</th>
                                    <th>Medication Assigned</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($history_list as $h): ?>
                                <tr>
                                    <td><?= date('F d, Y - h:i A', strtotime($h['issued_at'])) ?></td>
                                    <td><strong><?= htmlspecialchars($h['last_name'].", ".$h['first_name']) ?></strong></td>
                                    <td style="color:var(--accent-green); font-weight: 700;">
                                        <?= htmlspecialchars($h['med_name'] . " " . $h['strength'] . " (" . $h['form'] . ")") ?> (<?= htmlspecialchars($h['dosage']) ?>)
                                    </td>
                                    <td><span class="status-badge"><?= strtoupper($h['status']) ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Active Tab switching JavaScript -->
    <script>
        function switchTab(tabId) {
            const contents = document.querySelectorAll('.tab-content');
            contents.forEach(content => content.classList.remove('active'));

            const buttons = document.querySelectorAll('.tab-btn');
            buttons.forEach(btn => btn.classList.remove('active'));

            document.getElementById(tabId).classList.add('active');
            
            const activeBtn = Array.from(buttons).find(btn => btn.getAttribute('onclick').includes(tabId));
            if (activeBtn) {
                activeBtn.classList.add('active');
            }

            localStorage.setItem('activePrescriptionTab', tabId);
        }

        function updateStockDisplay() {
            const select = document.getElementById('medSelect');
            const display = document.getElementById('stockDisplay');
            if (select && display) {
                const selectedOption = select.options[select.selectedIndex];
                const stock = selectedOption.getAttribute('data-stock');
                if (stock !== null) {
                    display.innerText = "📦 Current Clinical Stock: " + stock + " units available";
                    if (parseInt(stock) <= 10) {
                        display.style.color = "#dc2626"; // red alert for low stock
                    } else {
                        display.style.color = "#16a34a"; // green for safe stock
                    }
                } else {
                    display.innerText = "";
                }
            }
        }

        function validatePrescriptionQuantity() {
            const select = document.getElementById('medSelect');
            const qtyInput = document.querySelector('input[name="quantity_ordered"]');
            const submitBtn = document.querySelector('button[name="add_prescription"]');
            const display = document.getElementById('stockDisplay');
            
            if (select && qtyInput && submitBtn && display) {
                const selectedOption = select.options[select.selectedIndex];
                if (!selectedOption || selectedOption.disabled) return;
                
                const stock = parseInt(selectedOption.getAttribute('data-stock') ?? '0');
                const ordered = parseInt(qtyInput.value || '0');
                
                if (ordered > stock) {
                    display.innerText = "⚠️ Error: Prescribed quantity (" + ordered + ") exceeds available stock (" + stock + ")!";
                    display.style.color = "#dc2626";
                    submitBtn.disabled = true;
                    submitBtn.style.opacity = "0.5";
                    submitBtn.style.cursor = "not-allowed";
                } else {
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = "1";
                    submitBtn.style.cursor = "pointer";
                    updateStockDisplay();
                }
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            const preservedTab = localStorage.getItem('activePrescriptionTab') || 'issue-prescription';
            if (preservedTab && document.getElementById(preservedTab)) {
                switchTab(preservedTab);
            }
            updateStockDisplay();
            validatePrescriptionQuantity();
        });
    </script>
</body>
</html>