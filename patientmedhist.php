<?php
/**
 * patientmedhist.php - Professional Medical History
 */
require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Access Control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
$user_id = $_SESSION['user_id'];
$success_msg = '';
$error = '';

try {
    $stmt = $pdo->prepare('SELECT patient_id FROM patient WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $patient_id = $stmt->fetchColumn();

    // Handle Creation Submission (Function preserved and fixed to use medical_request)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_record'])) {
        $doctor_id = (int)$_POST['doctor_id'];
        $notes = htmlspecialchars($_POST['notes']);
        // Fix: Save to medical_request table to comply with the DB schema constraints
        $stmt = $pdo->prepare("INSERT INTO medical_request (patient_id, doctor_id, request_type, description, status, requested_at) VALUES (?, ?, 'medical_record', ?, 'pending', NOW())");
        if ($stmt->execute([$patient_id, $doctor_id, $notes])) {
            $success_msg = "Medical record request sent successfully!";
        }
    }

    $doctors = $pdo->query("SELECT d.doctor_id, u.first_name, u.last_name FROM doctor d JOIN users u ON d.user_id = u.user_id")->fetchAll(PDO::FETCH_ASSOC);

    // Fetch medical record list with LEFT JOIN on referrals and test results
    $stmt = $pdo->prepare("
        SELECT mr.record_id, 
               mr.diagnosis, 
               mr.treatment_summary, 
               mr.notes, 
               COALESCE(mr.date_time, mr.created_at) as date_time,
               u.first_name as doc_fname, 
               u.last_name as doc_lname,
               r.referral_id, 
               r.to_whom as ref_specialist, 
               r.reason as ref_reason, 
               r.status as ref_status, 
               r.issued_at as ref_issued_at,
               tr.test_result_id,
               tr.result as test_result_text,
               tr.findings as test_findings
        FROM medical_record mr
        JOIN doctor d ON mr.doctor_id = d.doctor_id
        JOIN users u ON d.user_id = u.user_id
        LEFT JOIN referral r ON mr.record_id = r.record_id
        LEFT JOIN test_order tor ON mr.record_id = tor.record_id
        LEFT JOIN test_result tr ON tor.test_order_id = tr.test_order_id
        WHERE mr.patient_id = ?
        ORDER BY date_time DESC
    ");
    $stmt->execute([$patient_id]);
    $raw_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Grouping records to safely handle multiple referrals or test orders without duplicating rows
    $medical_records = [];
    foreach ($raw_records as $row) {
        $rid = $row['record_id'];
        if (!isset($medical_records[$rid])) {
            $medical_records[$rid] = [
                'record_id' => $row['record_id'],
                'date_time' => $row['date_time'],
                'doc_fname' => $row['doc_fname'],
                'doc_lname' => $row['doc_lname'],
                'diagnosis' => $row['diagnosis'],
                'treatment_summary' => $row['treatment_summary'],
                'notes' => $row['notes'],
                'referrals' => [],
                'test_results' => []
            ];
        }
        if ($row['referral_id']) {
            $medical_records[$rid]['referrals'][$row['referral_id']] = [
                'referral_id' => $row['referral_id'],
                'to_whom' => $row['ref_specialist'],
                'reason' => $row['ref_reason'],
                'status' => $row['ref_status'],
                'issued_at' => $row['ref_issued_at']
            ];
        }
        if ($row['test_result_id']) {
            $medical_records[$rid]['test_results'][$row['test_result_id']] = [
                'test_result_id' => $row['test_result_id'],
                'result' => $row['test_result_text'],
                'findings' => $row['test_findings']
            ];
        }
    }
} catch (Exception $e) { $error = $e->getMessage(); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical History | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a4d2e;
            --secondary: #09dbb8;
            --bg: #f4f9f4;
            --surface: #ffffff;
            --text-main: #1c2a1c;
            --text-muted: #6b7280;
            --border: #e5e7eb;
            --accent-blue: #3b82f6;
            --accent-teal: #0fb19b;
            --accent-purple: #8b5cf6;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background-color: var(--bg); color: var(--text-main); line-height: 1.6; }

        .header-nav {
            background: var(--primary); padding: 12px 5%; display: flex;
            justify-content: space-between; align-items: center;
            position: sticky; top: 0; z-index: 1000; box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .nav-brand img { height: 40px; filter: brightness(0) invert(1); }
        .logo { color: white; font-weight: 800; font-size: 1.5rem; text-decoration: none; }
        .nav-links { display: flex; gap: 15px; }
        .nav-links a {
            color: white;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            padding: 8px 15px;
            border-radius: 8px;
            background: rgba(255,255,255,0.1);
            transition: 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .nav-links a:hover, .nav-links a.active { background: var(--secondary); }

        .logout-btn {
            background: #d90429;
            color: white;
            padding: 8px 18px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            font-size: 12px;
            transition: 0.3s;
        }
        .logout-btn:hover { background: #b00220; }

        .container { max-width: 1200px; margin: 40px auto; padding: 0 24px; }

        .page-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 30px; }
        .page-header h1 { font-size: 2rem; color: var(--primary); font-weight: 800; }
        
        /* Professional Card */
        .card {
            background: var(--surface);
            border-radius: 24px;
            padding: 0; /* Let the table fill the edges if needed */
            box-shadow: 0 10px 25px rgba(26, 77, 46, 0.05);
            border: 1px solid rgba(0,0,0,0.02);
            overflow: hidden;
        }

        .card-inner { padding: 30px; }

        /* Buttons */
        .create-btn {
            background-color: var(--secondary); color: white; border: none; padding: 14px 24px;
            border-radius: 12px; font-weight: 700; cursor: pointer; font-size: 0.95rem;
            transition: 0.3s; display: inline-flex; align-items: center; gap: 10px;
        }
        .create-btn:hover { background: var(--primary); transform: translateY(-2px); box-shadow: 0 5px 15px rgba(26, 77, 46, 0.2); }

        /* Table Styling */
        .history-table { width: 100%; border-collapse: collapse; }
        .table-head-row { background: var(--primary); color: white; }
        .table-head-row th { 
            padding: 20px; text-align: left; font-size: 0.75rem; 
            text-transform: uppercase; letter-spacing: 1px; font-weight: 700;
        }
        
        /* Matching the multi-tone effect from your screenshot */
        .table-head-row th:nth-child(2) { background: #225636; }
        .table-head-row th:nth-child(3) { background: #2a5f40; }
        .table-head-row th:nth-child(4) { background: #32694a; }
        .table-head-row th:nth-child(5) { background: #3a7253; }

        .history-table td { padding: 20px; border-bottom: 1px solid #f1f5f9; color: var(--text-main); font-size: 0.9rem; vertical-align: middle; }
        .history-table tr:last-child td { border-bottom: none; }
        .history-table tr:hover td { background-color: #fafdfa; }

        /* Action Buttons */
        .btn-act { 
            padding: 8px 16px; border-radius: 8px; color: white; text-decoration: none; 
            font-weight: 700; font-size: 11px; display: inline-flex; align-items: center; gap: 6px;
            transition: 0.2s; text-transform: uppercase;
        }
        .btn-show { background: var(--accent-blue); }
        .btn-referral { background: var(--accent-teal); }
        .btn-record { background: var(--accent-purple); }
        .btn-act:hover { opacity: 0.9; transform: scale(1.05); }

        /* Modal Overlay and Styling */
        #modalOverlay, .modal-overlay { 
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
            background: rgba(26, 77, 46, 0.4); z-index: 2000; backdrop-filter: blur(4px);
            justify-content: center; align-items: center; 
        }
        .modal-box { 
            background: white; padding: 35px; border-radius: 24px; width: 100%; max-width: 480px; 
            box-shadow: 0 20px 50px rgba(0,0,0,0.15);
            animation: modalPop 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
        }
        @keyframes modalPop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }

        .modal-header {
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f1f5f9;
        }
        .modal-header h3 {
            color: var(--primary);
            font-weight: 800;
            font-size: 1.35rem;
            margin-bottom: 4px;
        }
        .modal-header-teal h3 {
            color: var(--accent-teal) !important;
        }
        .modal-header-purple h3 {
            color: var(--accent-purple) !important;
        }
        .detail-row {
            margin-bottom: 16px;
        }
        .detail-row .label {
            font-size: 0.7rem;
            font-weight: 800;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 6px;
        }
        .detail-row .value {
            font-size: 0.92rem;
            color: var(--text-main);
            font-weight: 600;
            background: #f8fafc;
            padding: 12px 16px;
            border-radius: 12px;
            border: 1.5px solid var(--border);
            line-height: 1.5;
        }
        .detail-row .value-notes {
            font-style: italic;
            font-weight: 500;
            color: #374151;
            border-left: 3px solid var(--primary);
            border-radius: 4px 12px 12px 4px;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 9999px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        .badge-success {
            background-color: #dcfce7;
            color: #15803d;
            border: 1.5px solid #bbf7d0;
        }
        .modal-box h3 { color: var(--primary); margin-bottom: 20px; font-weight: 800; }
        .form-label { display: block; font-size: 0.75rem; font-weight: 800; color: var(--text-muted); margin-bottom: 8px; text-transform: uppercase; }
        
        .form-input { 
            width: 100%; padding: 14px; margin-bottom: 20px; border: 1.5px solid var(--border); 
            border-radius: 12px; font-size: 0.95rem; outline: none; transition: 0.3s;
        }
        .form-input:focus { border-color: var(--secondary); box-shadow: 0 0 0 4px rgba(79, 119, 45, 0.1); }

        /* Alerts */
        .alert {
            padding: 16px; border-radius: 12px; margin-bottom: 24px; font-weight: 600; 
            display: flex; align-items: center; gap: 10px;
        }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #50a46e; }
    </style>
</head>
<body>

    <nav class="header-nav">
        <div class="nav-brand">
            <img src="images/Logo_only.png" alt="Health4Q">
        </div>  
        <div class="nav-links">
            <a href="patient-dashboard.php">🏠 Dashboard</a>
            <a href="patientprofile.php">👤 Profile</a>
            <a href="patientappoint.php">📅 Appointments</a>
            <a href="patient-prescriptions.php">💊 Prescriptions</a>
            <a href="patient-lab-results.php">🧪 Lab Results</a>
            <a href="patientmedhist.php" class="active">📜 History</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </nav>

<div class="container">
    <header class="page-header">
        <div>
            <h1>Medical History</h1>
            <p style="color: var(--text-muted);">View and manage your verified clinical records and referrals.</p>
        </div>
        <button class="create-btn" onclick="openModal()">
            <span>➕</span> Create New Record
        </button>
    </header>

    <?php if($success_msg): ?>
        <div class="alert alert-success">✅ <?php echo $success_msg; ?></div>
    <?php endif; ?>

    <div class="card">
        <table class="history-table">
            <thead>
                <tr class="table-head-row">
                    <th>📅 Date and Time</th>
                    <th>👤 Issued By</th>
                    <th>👁️ Action</th>
                    <th>📄 Referral</th>
                    <th>🧪 Test Result</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($medical_records)): ?>
                    <?php foreach ($medical_records as $record): ?>
                        <tr>
                            <td style="font-weight: 700; color: var(--primary);">
                                <?php echo date('M d, Y', strtotime($record['date_time'])); ?><br>
                                <small style="font-weight: 400; color: var(--text-muted);">
                                    <?php echo date('h:i A', strtotime($record['date_time'])); ?>
                                </small>
                            </td>
                            <td>Dr. <?php echo htmlspecialchars($record['doc_fname'].' '.$record['doc_lname']); ?></td>
                            
                            <!-- Action: View Clinical Record details -->
                            <td>
                                <button type="button" class="btn-act btn-show" onclick="openDetailsModal(<?php echo htmlspecialchars(json_encode($record), ENT_QUOTES, 'UTF-8'); ?>)">
                                    👁️ View Details
                                </button>
                            </td>
                            
                            <!-- Referral Details -->
                            <td>
                                <?php if (!empty($record['referrals'])): ?>
                                    <?php foreach ($record['referrals'] as $ref): ?>
                                        <button type="button" class="btn-act btn-referral" onclick="openReferralModal(<?php echo htmlspecialchars(json_encode($ref), ENT_QUOTES, 'UTF-8'); ?>, 'Dr. <?php echo htmlspecialchars($record['doc_fname'].' '.$record['doc_lname'], ENT_QUOTES, 'UTF-8'); ?>')">
                                            📄 View Referral
                                        </button>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="font-size: 0.85rem; color: var(--text-muted); font-style: italic;">No Referral</span>
                                <?php endif; ?>
                            </td>
                            
                            <!-- Test Result Details -->
                            <td>
                                <?php if (!empty($record['test_results'])): ?>
                                    <?php foreach ($record['test_results'] as $tr): ?>
                                        <button type="button" class="btn-act btn-record" onclick="openTestResultModal(<?php echo htmlspecialchars(json_encode($tr), ENT_QUOTES, 'UTF-8'); ?>, 'Dr. <?php echo htmlspecialchars($record['doc_fname'].' '.$record['doc_lname'], ENT_QUOTES, 'UTF-8'); ?>')">
                                            🧪 View Result
                                        </button>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="font-size: 0.85rem; color: var(--text-muted); font-style: italic;">No Tests</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="text-align:center; padding: 80px 20px;">
                            <div style="font-size: 3rem; margin-bottom: 10px;">📋</div>
                            <h3 style="color: var(--text-muted);">No Medical History Found</h3>
                            <p style="color: #94a3b8; font-size: 0.9rem;">Once doctors issue your records, they will appear here.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="modalOverlay">
    <div class="modal-box">
        <h3>New Clinical Record</h3>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 25px;">Submit your current symptoms for clinical review.</p>
        
        <form method="POST" id="recordForm">
            <label class="form-label">Assign to Doctor</label>
            <select name="doctor_id" class="form-input" required>
                <option value="" disabled selected>Select Specialist</option>
                <?php foreach($doctors as $d): ?>
                    <option value="<?php echo $d['doctor_id']; ?>">Dr. <?php echo $d['first_name'].' '.$d['last_name']; ?></option>
                <?php endforeach; ?>
            </select>

            <label class="form-label">Symptoms / Notes</label>
            <textarea name="notes" class="form-input" rows="4" placeholder="How are you feeling today?" required></textarea>
            
            <button type="submit" name="submit_record" class="create-btn" style="width:100%; justify-content: center; margin-bottom: 12px;">
                Confirm & Send
            </button>
            <button type="button" onclick="closeModal()" style="background:none; border:none; width:100%; color:var(--text-muted); cursor:pointer; font-weight:600; font-size: 0.9rem;">
                Dismiss
            </button>
        </form>
    </div>
</div>

<!-- Details Modal -->
<div id="detailsModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Clinical Record Details</h3>
            <p style="font-size: 0.85rem; color: var(--text-muted);">Detailed diagnostic summary and physician plan.</p>
        </div>
        <div class="detail-row">
            <div class="label">📅 Encounter Date</div>
            <div class="value" id="det-date"></div>
        </div>
        <div class="detail-row">
            <div class="label">👤 Attending Doctor</div>
            <div class="value" id="det-doctor"></div>
        </div>
        <div class="detail-row">
            <div class="label">🩺 Primary Diagnosis</div>
            <div class="value" id="det-diagnosis" style="font-weight: 700; color: var(--primary);"></div>
        </div>
        <div class="detail-row">
            <div class="label">📋 Treatment Summary / Plan</div>
            <div class="value" id="det-treatment"></div>
        </div>
        <div class="detail-row">
            <div class="label">✍️ Physician Notes</div>
            <div class="value value-notes" id="det-notes"></div>
        </div>
        <button type="button" onclick="closeDetailsModal()" class="create-btn" style="width:100%; justify-content: center; background: var(--primary);">
            Close View
        </button>
    </div>
</div>

<!-- Referral Modal -->
<div id="referralModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header modal-header-teal">
            <h3>Issued Specialist Referral</h3>
            <p style="font-size: 0.85rem; color: var(--text-muted);">Clinical referral letter issued by your physician.</p>
        </div>
        <div class="detail-row">
            <div class="label">📅 Date Issued</div>
            <div class="value" id="ref-date"></div>
        </div>
        <div class="detail-row">
            <div class="label">👤 Referring Doctor</div>
            <div class="value" id="ref-doctor"></div>
        </div>
        <div class="detail-row">
            <div class="label">🏢 Target Specialist / Department</div>
            <div class="value" id="ref-specialist" style="font-weight: 700; color: var(--accent-teal);"></div>
        </div>
        <div class="detail-row">
            <div class="label">💡 Reason for Referral</div>
            <div class="value" id="ref-reason"></div>
        </div>
        <div class="detail-row">
            <div class="label">🏷️ Referral Status</div>
            <div>
                <span class="badge badge-success" id="ref-status"></span>
            </div>
        </div>
        <button type="button" onclick="closeReferralModal()" class="create-btn" style="width:100%; justify-content: center; background: var(--accent-teal);">
            Close View
        </button>
    </div>
</div>

<!-- Test Result Modal -->
<div id="testResultModal" class="modal-overlay">
    <div class="modal-box">
        <div class="modal-header modal-header-purple">
            <h3>Laboratory & Diagnostic Results</h3>
            <p style="font-size: 0.85rem; color: var(--text-muted);">Verified clinical laboratory test findings.</p>
        </div>
        <div class="detail-row">
            <div class="label">👤 Ordered By</div>
            <div class="value" id="test-doctor"></div>
        </div>
        <div class="detail-row">
            <div class="label">🧪 Diagnostic Findings</div>
            <div class="value" id="test-findings" style="font-weight: 700; color: var(--accent-purple);"></div>
        </div>
        <div class="detail-row">
            <div class="label">📊 Result Summary</div>
            <div class="value" id="test-result"></div>
        </div>
        <button type="button" onclick="closeTestResultModal()" class="create-btn" style="width:100%; justify-content: center; background: var(--accent-purple);">
            Close View
        </button>
    </div>
</div>

<script>
    function openModal() { 
        document.getElementById('modalOverlay').style.display = 'flex'; 
        document.body.style.overflow = 'hidden'; 
    }
    function closeModal() { 
        document.getElementById('modalOverlay').style.display = 'none'; 
        document.body.style.overflow = 'auto'; 
    }

    function openDetailsModal(record) {
        document.getElementById('det-date').innerText = formatDate(record.date_time);
        document.getElementById('det-doctor').innerText = "Dr. " + record.doc_fname + " " + record.doc_lname;
        document.getElementById('det-diagnosis').innerText = record.diagnosis ? record.diagnosis : "N/A";
        document.getElementById('det-treatment').innerText = record.treatment_summary ? record.treatment_summary : "N/A";
        document.getElementById('det-notes').innerText = record.notes ? record.notes : "No clinical notes added.";
        
        document.getElementById('detailsModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    function closeDetailsModal() {
        document.getElementById('detailsModal').style.display = 'none';
        document.body.style.overflow = 'auto';
    }

    function openReferralModal(ref, doctorName) {
        document.getElementById('ref-date').innerText = formatDate(ref.issued_at);
        document.getElementById('ref-doctor').innerText = doctorName;
        document.getElementById('ref-specialist').innerText = ref.to_whom;
        document.getElementById('ref-reason').innerText = ref.reason ? ref.reason : "N/A";
        document.getElementById('ref-status').innerText = ref.status;
        
        document.getElementById('referralModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    function closeReferralModal() {
        document.getElementById('referralModal').style.display = 'none';
        document.body.style.overflow = 'auto';
    }

    function openTestResultModal(tr, doctorName) {
        document.getElementById('test-doctor').innerText = doctorName;
        document.getElementById('test-findings').innerText = tr.findings ? tr.findings : "N/A";
        document.getElementById('test-result').innerText = tr.result ? tr.result : "N/A";
        
        document.getElementById('testResultModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    function closeTestResultModal() {
        document.getElementById('testResultModal').style.display = 'none';
        document.body.style.overflow = 'auto';
    }

    function formatDate(dateStr) {
        if (!dateStr) return "N/A";
        let date = new Date(dateStr);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' + 
               date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    }

    // Close modals when clicking outside of the box
    window.onclick = function(event) {
        let modals = [
            document.getElementById('modalOverlay'),
            document.getElementById('detailsModal'),
            document.getElementById('referralModal'),
            document.getElementById('testResultModal')
        ];
        modals.forEach(modal => {
            if (event.target == modal) {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
        });
    }
</script>

</body>
</html>