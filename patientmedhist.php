<?php
/**
 * patientmedhist.php - Professional Medical History
 * Matches Forest Green / Mint UI
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

    // Handle Creation Submission (Function preserved)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_record'])) {
        $doctor_id = (int)$_POST['doctor_id'];
        $notes = htmlspecialchars($_POST['notes']);
        $stmt = $pdo->prepare("INSERT INTO medical_records (patient_id, doctor_id, notes, status, date_time) VALUES (?, ?, ?, 'pending', NOW())");
        if ($stmt->execute([$patient_id, $doctor_id, $notes])) {
            $success_msg = "Medical record request sent successfully!";
        }
    }

    $doctors = $pdo->query("SELECT d.doctor_id, u.first_name, u.last_name FROM doctor d JOIN users u ON d.user_id = u.user_id")->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
        SELECT mr.*, u.first_name as doc_fname, u.last_name as doc_lname
        FROM medical_records mr
        JOIN doctor d ON mr.doctor_id = d.doctor_id
        JOIN users u ON d.user_id = u.user_id
        WHERE mr.patient_id = ?
        ORDER BY mr.date_time DESC
    ");
    $stmt->execute([$patient_id]);
    $medical_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

        /* Navigation (Same as Appointment UI) */
        .header-nav {
            background: var(--primary);
            padding: 1rem 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .nav-brand img { height: 40px; filter: brightness(0) invert(1); }
        .logo { color: white; font-weight: 800; font-size: 1.5rem; text-decoration: none; }
        .nav-links a { color: rgba(255,255,255,0.8); text-decoration: none; margin-left: 24px; font-size: 0.9rem; transition: 0.3s; }
        .nav-links a:hover, .nav-links a.active { color: white; font-weight: 600; }

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

        /* Modal Overlay */
        #modalOverlay { 
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
            background: rgba(26, 77, 46, 0.4); z-index: 2000; backdrop-filter: blur(4px);
            justify-content: center; align-items: center; 
        }
        .modal-box { 
            background: white; padding: 35px; border-radius: 24px; width: 100%; max-width: 450px; 
            box-shadow: 0 20px 50px rgba(0,0,0,0.15);
            animation: modalPop 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        @keyframes modalPop { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }

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
        <a href="patient-dashboard.php">Dashboard</a>
        <a href="patientprofile.php">My Profile</a>
        <a href="patientappoint.php">Appointments</a>
        <a href="patientmedhist.php" class="active">Medical History</a>
        <a href="logout.php" style="color: #ff9999;">Logout</a>
    </div>
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
                            <td><a href="#" class="btn-act btn-show">👁️ View Details</a></td>
                            <td><a href="#" class="btn-act btn-referral">📄 Request</a></td>
                            <td><a href="#" class="btn-act btn-record">💉 Results</a></td>
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

<script>
    function openModal() { 
        document.getElementById('modalOverlay').style.display = 'flex'; 
        document.body.style.overflow = 'hidden'; // Prevent scroll
    }
    function closeModal() { 
        document.getElementById('modalOverlay').style.display = 'none'; 
        document.body.style.overflow = 'auto'; 
    }

    // Close modal when clicking outside of the box
    window.onclick = function(event) {
        let modal = document.getElementById('modalOverlay');
        if (event.target == modal) closeModal();
    }
</script>

</body>
</html>