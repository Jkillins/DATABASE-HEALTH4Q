<?php
/**
 * doctor-lab-orders.php
 */

require_once 'config.php';
requireRole(ROLE_DOCTOR);

$pdo = getPDO();
$doctor_id = getCurrentRoleId();
$message = '';
$message_type = 'success';

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

// 2. Fetch test types
$test_types = [];
try {
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
    <title>Lab Orders | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a4d34;
            --accent: #2d6a4f;
            --light-bg: #c5e6e1;
            --white: #ffffff;
            --text: #1b4332;
            --border: #d0e8e0;
            --success: #52b788;
            --danger: #d90429;
            --warning: #f77f00;
            --info: #4361ee;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Quicksand', sans-serif;
            background: radial-gradient(circle at center, #d8f3dc 0%, var(--light-bg) 100%);
            color: var(--text);
            min-height: 100vh;
        }

        /* Navigation */
        .top-nav {
            background: var(--primary);
            padding: 15px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            color: white;
            font-weight: 700;
        }

        .nav-brand img { height: 40px; filter: brightness(0) invert(1); }

        .nav-links {
            display: flex;
            gap: 20px;
        }

        .nav-links a {
            color: white;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            opacity: 0.8;
            transition: 0.3s;
        }

        .nav-links a:hover, .nav-links a.active { opacity: 1; border-bottom: 2px solid white; }

        .logout-btn { color: #ffcccc; font-size: 12px; font-weight: bold; text-decoration: none; }

        .container { max-width: 1200px; margin: 40px auto; padding: 0 20px; }

        /* Message Styling */
        .message {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-weight: 600;
            border-left: 5px solid;
        }

        .message.success {
            background: #d4edda;
            border-color: var(--success);
            color: #155724;
        }

        .message.error {
            background: #f8d7da;
            border-color: var(--danger);
            color: #721c24;
        }

        /* Cards */
        .card {
            background: var(--white);
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            border: 1px solid var(--border);
        }

        .card-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 25px;
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
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            margin-bottom: 8px;
            color: #666;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 12px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-family: inherit;
            background: #fff;
            cursor: pointer;
            transition: 0.3s;
            font-size: 13px;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--accent);
            background: var(--light-bg);
        }

        .btn-dispatch {
            background: var(--accent);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
        }

        .btn-dispatch:hover { background: var(--primary); transform: translateY(-2px); }

        /* Table Styling */
        .table-container { overflow-x: auto; }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        thead {
            background: #f8fcfb;
        }

        th {
            text-align: left;
            padding: 15px;
            font-size: 11px;
            color: #888;
            text-transform: uppercase;
            border-bottom: 2px solid var(--border);
            font-weight: 700;
        }

        td {
            padding: 18px 15px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
        }

        tbody tr:hover { background: #f9f9f9; }

        /* Status Badge */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            display: inline-block;
        }

        .status-ordered { background: #fff3cd; color: #856404; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-canceled { background: #f8d7da; color: #721c24; }

        .view-result {
            color: var(--accent);
            cursor: pointer;
            font-weight: 700;
            text-decoration: none;
            transition: 0.3s;
        }

        .view-result:hover { color: var(--primary); text-decoration: underline; }

        /* Modal Styling */
        #resModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            z-index: 999;
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
        }

        .close-modal {
            position: absolute;
            top: 15px;
            right: 20px;
            cursor: pointer;
            font-size: 28px;
            color: #999;
            font-weight: bold;
        }

        .close-modal:hover { color: #333; }

        .modal-section {
            margin-bottom: 20px;
        }

        .modal-section label {
            font-weight: 700;
            font-size: 11px;
            color: #888;
            text-transform: uppercase;
            display: block;
            margin-bottom: 10px;
        }

        .modal-section p {
            background: #f9f9f9;
            padding: 12px;
            border-radius: 5px;
            white-space: pre-wrap;
            font-size: 13px;
            line-height: 1.5;
        }

        .filter-box {
            padding: 8px 15px;
            border: 1px solid var(--border);
            border-radius: 5px;
            width: 220px;
            font-size: 13px;
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

    <nav class="top-nav">
        <div class="nav-brand">
            <img src="images/Logo_only.png" alt="Health4Q">
            <span style="color: white;">Lab Orders</span>
        </div>
        <div class="nav-links">
            <a href="doctor-dashboard.php">Dashboard</a>
            <a href="doctor-patient-list.php">Patients</a>
            <a href="doctor-lab-orders.php" class="active">Lab Orders</a>
            <a href="doctor-medical-records.php">Records</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
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
                    <select name="test_type_id" required>
                        <option value="" disabled selected>-- Select Test Type --</option>
                        <?php foreach ($test_types as $tt): ?>
                            <option value="<?php echo $tt['test_type_id']; ?>">
                                <?php echo htmlspecialchars($tt['name']); ?><?php echo ($tt['description'] ? ' - ' . htmlspecialchars($tt['description']) : ''); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
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
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div class="card-title" style="margin-bottom: 0;">📋 Order History</div>
                <input type="text" id="filterInput" placeholder="Filter by patient..." class="filter-box">
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Ordered Date</th>
                            <th>Patient</th>
                            <th>Test Type</th>
                            <th>Status</th>
                            <th>Result</th>
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
                                        <a href="#" class="view-result" onclick="showModal('<?php echo addslashes($o['lab_result'] ?? 'N/A'); ?>', '<?php echo addslashes($o['lab_findings'] ?? 'No findings'); ?>', '<?php echo addslashes($o['test_name']); ?>'); return false;">
                                            View Results
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #ccc; font-style: italic;">Pending...</span>
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

    <!-- RESULT MODAL -->
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

    <script>
        function showModal(result, findings, testName) {
            document.getElementById('mTestName').innerText = testName || 'Laboratory Report';
            document.getElementById('mResult').innerText = result || 'N/A';
            document.getElementById('mFindings').innerText = findings || 'No detailed findings recorded.';
            document.getElementById('resModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('resModal').style.display = 'none';
        }

        // Live filtering
        document.getElementById('filterInput').addEventListener('keyup', function() {
            let filter = this.value.toLowerCase();
            let rows = document.querySelectorAll('#orderTable tr');
            rows.forEach(row => {
                row.style.display = row.innerText.toLowerCase().includes(filter) ? '' : 'none';
            });
        });

        // Close modal on background click
        window.onclick = function(e) {
            if (e.target === document.getElementById('resModal')) {
                closeModal();
            }
        }
    </script>
</body>
</html>