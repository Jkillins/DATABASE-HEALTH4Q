<?php
/**
 * doctor-lab-orders.php - PREMIUM CLINICAL LAB ORDERS & RESULTS INPUT
 */

require_once 'config.php';
requireRole(ROLE_DOCTOR);

$pdo = getPDO();
$doctor_id = getCurrentRoleId();
$user_id = $_SESSION['user_id'] ?? 0;
if (!$doctor_id && $user_id) {
    $stmt_doc = $pdo->prepare("SELECT doctor_id FROM doctor WHERE user_id = ?");
    $stmt_doc->execute([$user_id]);
    $doctor_id = $stmt_doc->fetchColumn() ?: null;
}
$message = '';
$message_type = 'success';

// --- HANDLE POST REQUEST: SUBMIT LAB RESULT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_result'])) {
    $test_order_id = (int)($_POST['test_order_id'] ?? 0);
    $result_data = trim($_POST['result_data'] ?? '');
    $findings_data = trim($_POST['findings_data'] ?? '');

    if (!$test_order_id || empty($result_data)) {
        $message = '✗ Result data cannot be empty.';
        $message_type = 'error';
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Insert into test_result
            $stmtRes = $pdo->prepare('
                INSERT INTO test_result (test_order_id, result, findings, date_time)
                VALUES (?, ?, ?, NOW())
            ');
            $stmtRes->execute([$test_order_id, $result_data, $findings_data]);

            // 2. Update test_order status to "completed"
            $stmtOrder = $pdo->prepare('
                UPDATE test_order SET status = "completed" WHERE test_order_id = ?
            ');
            $stmtOrder->execute([$test_order_id]);

            $pdo->commit();
            $message = '✓ Lab test result submitted successfully!';
            $message_type = 'success';

            // Refresh the page
            header("Location: doctor-lab-orders.php?msg=success");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = '✗ Error: ' . htmlspecialchars($e->getMessage());
            $message_type = 'error';
        }
    }
}
if (isset($_GET['msg']) && $_GET['msg'] === 'success') {
    $message = '✓ Lab test result submitted successfully!';
    $message_type = 'success';
}

// --- HANDLE POST REQUEST: CREATE NEW LAB ORDER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_order'])) {
    $patient_id = (int)($_POST['patient_id'] ?? 0);
    $test_type_id = (int)($_POST['test_type_id'] ?? 0);
    $urgency = sanitize($_POST['urgency'] ?? 'normal');
    $special_instructions = sanitize($_POST['special_instructions'] ?? '');

    if (!$patient_id || !$test_type_id) {
        $message = '✗ Please select both a patient and a test type.';
        $message_type = 'error';
    } elseif (!in_array($urgency, ['normal', 'urgent', 'STAT'])) {
        $message = '✗ Invalid urgency level selected.';
        $message_type = 'error';
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Fetch latest medical record for patient
            $stmtRecord = $pdo->prepare('
                SELECT record_id FROM medical_record 
                WHERE patient_id = ? 
                ORDER BY date_time DESC LIMIT 1
            ');
            $stmtRecord->execute([$patient_id]);
            $record = $stmtRecord->fetch();

            if (!$record) {
                throw new Exception("Patient has no medical record. Please create a consultation record first.");
            }

            // 2. Insert test order
            $stmt = $pdo->prepare('
                INSERT INTO test_order (record_id, ordered_by, test_type_id, status, ordered_at)
                VALUES (?, ?, ?, "ordered", NOW())
            ');
            $stmt->execute([$record['record_id'], $doctor_id, $test_type_id]);
            
            $pdo->commit();
            $message = '✓ Lab order created successfully!';
            $message_type = 'success';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = '✗ Error: ' . htmlspecialchars($e->getMessage());
            $message_type = 'error';
        }
    }
}

// --- FETCH DATA FOR DISPLAY ---

// 1. Fetch all lab orders for this doctor
$orders = [];
try {
    $stmt = $pdo->prepare('
        SELECT 
            to_.test_order_id,
            to_.status,
            to_.ordered_at,
            to_.record_id,
            tt.name as test_name,
            tt.description as test_description,
            u.first_name,
            u.last_name,
            p.patient_id,
            COALESCE(tr.test_result_id, 0) as has_result,
            tr.result as lab_result,
            tr.findings as lab_findings,
            tr.date_time as result_date
        FROM test_order to_
        JOIN medical_record mr ON to_.record_id = mr.record_id
        JOIN patient p ON mr.patient_id = p.patient_id
        JOIN users u ON p.user_id = u.user_id
        JOIN test_type tt ON to_.test_type_id = tt.test_type_id
        LEFT JOIN test_result tr ON to_.test_order_id = tr.test_order_id
        WHERE to_.ordered_by = ?
        ORDER BY to_.ordered_at DESC
    ');
    $stmt->execute([$doctor_id]);
    $orders = $stmt->fetchAll();
} catch (Exception $e) {
    $message = '✗ Error loading orders: ' . htmlspecialchars($e->getMessage());
    $message_type = 'error';
}

// 2. Fetch test types (with auto-seeding if empty)
$test_types = [];
try {
    // Check if table is empty and auto-seed if necessary
    $count = (int)$pdo->query('SELECT COUNT(*) FROM test_type')->fetchColumn();
    if ($count === 0) {
        $default_types = [
            ['CBC (Complete Blood Count)', 'Measures red blood cells, white blood cells, platelets, hemoglobin, and hematocrit.'],
            ['Lipid Panel', 'Measures cholesterol levels (HDL, LDL, and triglycerides) to assess cardiovascular health.'],
            ['Urinalysis', 'Detects urinary tract infections, kidney function, and diabetes markers.'],
            ['Blood Glucose (Fasting)', 'Measures blood sugar level to screen for diabetes or prediabetes.'],
            ['Thyroid Panel (TSH, Free T4)', 'Evaluates thyroid gland function and helps diagnose thyroid disorders.'],
            ['Liver Function Tests (LFT)', 'Measures enzymes and proteins to assess liver health.'],
            ['Basic Metabolic Panel (BMP)', 'Measures kidney function, electrolytes, and blood sugar levels.']
        ];
        $stmtInsert = $pdo->prepare('INSERT INTO test_type (name, description) VALUES (?, ?)');
        foreach ($default_types as $type) {
            $stmtInsert->execute($type);
        }
    }

    $stmt = $pdo->query('SELECT test_type_id, name, description FROM test_type ORDER BY name ASC');
    $test_types = $stmt->fetchAll();
} catch (Exception $e) {
    $message = '✗ Error loading test types: ' . htmlspecialchars($e->getMessage());
    $message_type = 'error';
}

// 3. Fetch patients
$patients = [];
try {
    $stmt = $pdo->query('
        SELECT p.patient_id, u.first_name, u.last_name, u.email
        FROM patient p 
        JOIN users u ON p.user_id = u.user_id 
        ORDER BY u.last_name ASC, u.first_name ASC
    ');
    $patients = $stmt->fetchAll();
} catch (Exception $e) {
    $message = '✗ Error loading patients: ' . htmlspecialchars($e->getMessage());
    $message_type = 'error';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Lab Orders & Results | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a4d34;
            --primary-light: #2d6a4f;
            --bg-soft: #f4f9f7;
            --white: #ffffff;
            --danger: #d90429;
            --warning: #ffb703;
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

        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

        /* Message Styling */
        .message {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-weight: 700;
            font-size: 14px;
            text-align: center;
        }
        .message.success { background: #b7e4c7; color: #1b4332; border: 1px solid #95d5b2; }
        .message.error { background: #ffccd5; color: #a4133c; border: 1px solid #ffb3c1; }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            border: 1px solid var(--border);
        }

        .card-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 20px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Form Styling */
        .order-form {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr auto;
            gap: 20px;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--primary);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-family: inherit;
            outline: none;
            transition: 0.3s;
            font-size: 14px;
            font-weight: 600;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(45, 106, 79, 0.1);
        }

        .btn-dispatch {
            background: var(--primary);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            font-size: 14px;
            transition: 0.2s;
        }

        .btn-dispatch:hover { background: var(--primary-light); transform: translateY(-1px); }

        /* Table Styling */
        .table-container { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 15px;
            font-size: 11px;
            color: #6c757d;
            text-transform: uppercase;
            border-bottom: 2px solid var(--border);
            font-weight: 800;
        }

        td {
            padding: 18px 15px;
            border-bottom: 1px solid #f1f3f5;
            font-size: 14px;
            font-weight: 600;
        }

        tbody tr:hover { background: #f8faf9; }

        /* Status Badge */
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-ordered { background: #fff9db; color: #947100; }
        .status-completed { background: #e6f4f1; color: var(--success); }
        .status-canceled { background: #ffe3e3; color: var(--danger); }

        .view-result {
            color: var(--primary-light);
            cursor: pointer;
            font-weight: 700;
            text-decoration: none;
            transition: 0.3s;
        }

        .view-result:hover { color: var(--primary); text-decoration: underline; }

        /* Modal Styling */
        #resModal, #inputResultModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.4);
            backdrop-filter: blur(4px);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }

        .modal-body {
            background: white;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
            position: relative;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }

        .close-modal {
            position: absolute;
            top: 15px;
            right: 20px;
            cursor: pointer;
            font-size: 24px;
            color: #aaa;
            font-weight: bold;
            transition: 0.2s;
        }

        .close-modal:hover { color: var(--danger); }

        .modal-section {
            margin-bottom: 20px;
        }

        .modal-section label {
            font-weight: 700;
            font-size: 12px;
            color: var(--primary);
            text-transform: uppercase;
            display: block;
            margin-bottom: 8px;
        }

        .modal-section p {
            background: var(--bg-soft);
            padding: 12px 15px;
            border-radius: 8px;
            white-space: pre-wrap;
            font-size: 14px;
            font-weight: 600;
            line-height: 1.5;
            border-left: 3px solid rgba(45, 106, 79, 0.3);
        }

        .filter-box {
            padding: 10px 15px;
            border: 1px solid var(--border);
            border-radius: 8px;
            width: 250px;
            outline: none;
            font-size: 13px;
            font-weight: 600;
        }
        .filter-box:focus {
            border-color: var(--primary-light);
            box-shadow: 0 0 0 3px rgba(45, 106, 79, 0.1);
        }

        @media (max-width: 1024px) {
            .order-form { grid-template-columns: 1fr 1fr; }
            .btn-dispatch { grid-column: 1 / -1; }
        }

        @media (max-width: 768px) {
            .order-form { grid-template-columns: 1fr; }
            .table-container { font-size: 12px; }
            th, td { padding: 10px; }
        }
    </style>
</head>
<body>

    <nav class="navbar">
        <div class="nav-brand"><img src="images/Logo_only.png" alt="Health4Q"></div>
        <div class="nav-links">
            <a href="doctor-dashboard.php">Dashboard</a>
            <a href="doctor-patient-list.php">Patients</a>
            <a href="doctor-prescriptions.php">Medicine</a>
            <a href="doctor-profile.php">Profile</a>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </nav>

    <div class="container">
        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- REQUEST NEW ANALYSIS -->
        <div class="card">
            <div class="card-title">🧪 Request New Laboratory Analysis</div>
            <form method="POST" class="order-form">
                <div class="form-group">
                    <label>Patient *</label>
                    <select name="patient_id" required>
                        <option value="" disabled selected>-- Choose Patient --</option>
                        <?php foreach ($patients as $p): ?>
                            <option value="<?php echo $p['patient_id']; ?>">
                                <?php echo htmlspecialchars($p['last_name'] . ', ' . $p['first_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Test Type *</label>
                    <select name="test_type_id" id="testTypeSelect" required>
                        <option value="" disabled selected>-- Select Test Type --</option>
                        <?php foreach ($test_types as $tt): ?>
                            <option value="<?php echo $tt['test_type_id']; ?>" data-desc="<?php echo htmlspecialchars($tt['description'] ?? ''); ?>">
                                <?php echo htmlspecialchars($tt['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="testTypeDesc" style="font-size: 11px; color: var(--primary); margin-top: 6px; font-style: italic; display: none; background: #e8f5e9; padding: 6px 10px; border-radius: 6px; border-left: 3px solid var(--primary-light);"></div>
                </div>
                <div class="form-group">
                    <label>Urgency</label>
                    <select name="urgency">
                        <option value="normal" selected>Normal</option>
                        <option value="urgent">Urgent</option>
                        <option value="STAT">STAT</option>
                    </select>
                </div>
                <button type="submit" name="add_order" class="btn-dispatch">Dispatch</button>
            </form>
        </div>

        <!-- LAB ORDER HISTORY -->
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <div class="card-title" style="margin-bottom: 0; border: none; padding: 0;">📋 Laboratory Order History</div>
                <input type="text" id="filterInput" placeholder="Search by patient name..." class="filter-box">
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Ordered Date</th>
                            <th>Patient</th>
                            <th>Test Type</th>
                            <th>Status</th>
                            <th>Results Ledger</th>
                        </tr>
                    </thead>
                    <tbody id="orderTable">
                        <?php if (count($orders) === 0): ?>
                            <tr><td colspan="5" style="text-align: center; padding: 40px; color: #aaa;">No laboratory orders found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($orders as $o): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($o['ordered_at'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($o['first_name'] . ' ' . $o['last_name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($o['test_name']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower($o['status']); ?>">
                                        <?php echo ucfirst($o['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($o['has_result'] > 0): ?>
                                        <button type="button" class="view-result" style="background: none; border: none; font-size: inherit; font-family: inherit; padding: 0; outline: none;" onclick="showModal(<?php echo htmlspecialchars(json_encode($o), ENT_QUOTES, 'UTF-8'); ?>)">
                                            👁️ View Results
                                        </button>
                                    <?php elseif ($o['status'] === 'canceled'): ?>
                                        <span style="color: var(--danger); font-weight: bold; text-transform: uppercase; font-size: 11px;">Canceled</span>
                                    <?php else: ?>
                                        <button type="button" class="view-result" style="background: none; border: none; font-size: inherit; font-family: inherit; padding: 0; outline: none; color: var(--primary-light);" onclick="openInputModal(<?php echo $o['test_order_id']; ?>, '<?php echo addslashes($o['first_name'] . ' ' . $o['last_name']); ?>', '<?php echo addslashes($o['test_name']); ?>')">
                                            ✍️ Input Result
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- RESULT VIEW MODAL -->
    <div id="resModal">
        <div class="modal-body">
            <span class="close-modal" onclick="closeModal()">&times;</span>
            <h3 style="color: var(--primary); margin-bottom: 20px;">📊 <span id="mTestName">Laboratory Report</span></h3>
            
            <div class="modal-section">
                <label>Result Data</label>
                <p id="mResult">N/A</p>
            </div>
            
            <div class="modal-section">
                <label>Doctor Findings</label>
                <p id="mFindings">No findings recorded.</p>
            </div>
        </div>
    </div>

    <!-- INPUT RESULT MODAL -->
    <div id="inputResultModal">
        <div class="modal-body" style="max-width: 500px;">
            <span class="close-modal" onclick="closeInputModal()">&times;</span>
            <h3 style="color: var(--primary); margin-bottom: 15px;">✍️ Input Lab Test Result</h3>
            <p id="inputModalLabel" style="font-size: 13px; color: #555; margin-bottom: 20px; font-weight: 600;"></p>
            
            <form method="POST">
                <input type="hidden" name="test_order_id" id="inputOrderId">
                
                <div class="form-group" style="margin-bottom: 15px;">
                    <label>Test Result / Quantitative Values *</label>
                    <textarea name="result_data" rows="5" required placeholder="e.g. Hemoglobin: 14.2 g/dL, Platelets: 250,000 /uL" style="width:100%; resize:vertical;"></textarea>
                </div>
                
                <div class="form-group" style="margin-bottom: 20px;">
                    <label>Diagnostic Findings & Clinical Advice</label>
                    <textarea name="findings_data" rows="3" placeholder="e.g. Values are within standard physiological range." style="width:100%; resize:vertical;"></textarea>
                </div>
                
                <button type="submit" name="submit_result" class="btn-dispatch" style="width: 100%;">
                    💾 Submit Test Result
                </button>
            </form>
        </div>
    </div>

    <script>
        function showModal(order) {
            document.getElementById('mTestName').innerText = order.test_name || 'Laboratory Report';
            document.getElementById('mResult').innerText = order.lab_result || 'N/A';
            document.getElementById('mFindings').innerText = order.lab_findings || 'No detailed findings recorded.';
            document.getElementById('resModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('resModal').style.display = 'none';
        }

        function openInputModal(orderId, patientName, testName) {
            document.getElementById('inputOrderId').value = orderId;
            document.getElementById('inputModalLabel').innerText = "Submitting results for: " + patientName + " (" + testName + ")";
            document.getElementById('inputResultModal').style.display = 'flex';
        }

        function closeInputModal() {
            document.getElementById('inputResultModal').style.display = 'none';
        }

        // Live filtering
        document.getElementById('filterInput').addEventListener('keyup', function() {
            let filter = this.value.toLowerCase();
            let rows = document.querySelectorAll('#orderTable tr');
            rows.forEach(row => {
                row.style.display = row.innerText.toLowerCase().includes(filter) ? '' : 'none';
            });
        });

        // Close modals on background click
        window.onclick = function(e) {
            if (e.target === document.getElementById('resModal')) {
                closeModal();
            } else if (e.target === document.getElementById('inputResultModal')) {
                closeInputModal();
            }
        }

        // Dynamic Test Type description display
        document.getElementById('testTypeSelect').addEventListener('change', function() {
            let selectedOption = this.options[this.selectedIndex];
            let desc = selectedOption.getAttribute('data-desc');
            let descDiv = document.getElementById('testTypeDesc');
            if (desc && desc.trim() !== '') {
                descDiv.innerText = '💡 Info: ' + desc;
                descDiv.style.display = 'block';
            } else {
                descDiv.style.display = 'none';
            }
        });
    </script>
</body>
</html>