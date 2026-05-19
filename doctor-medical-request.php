<?php
/**
 * doctor-medical-request.php - PREMIUM CLINICAL REQUEST CENTER (DATA & MEDICAL RELEASE)
 */
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
requireRole('doctor');

$pdo = getPDO();
$user_id = $_SESSION['user_id'];
$success_msg = '';
$error_msg = '';

try {
    // 1. Get internal doctor_id
    $stmtDoc = $pdo->prepare("SELECT doctor_id FROM doctor WHERE user_id = ?");
    $stmtDoc->execute([$user_id]);
    $doctor = $stmtDoc->fetch();
    $doctor_id = $doctor['doctor_id'] ?? 0;

    // 2. Handle Decision Submissions (POST Action Controller)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];

        // Action A: Approve/Reject Data Access Request
        if ($action === 'decide_data_request') {
            $req_id = (int)$_POST['request_id'];
            $new_status = $_POST['status']; // 'approved' or 'rejected'
            
            $stmt = $pdo->prepare("UPDATE data_requests SET status = ? WHERE id = ? AND doctor_id = ?");
            if ($stmt->execute([$new_status, $req_id, $doctor_id])) {
                $success_msg = "📂 Data access request has been successfully marked as " . htmlspecialchars($new_status) . ".";
            }
        }

        // Action B: Process Medical Release Request
        if ($action === 'decide_medical_request') {
            $req_id = (int)$_POST['request_id'];
            $new_status = $_POST['status']; // 'approved', 'rejected', 'completed'
            $reason = sanitize($_POST['rejection_reason'] ?? '');
            
            $stmt = $pdo->prepare("
                UPDATE medical_request 
                SET status = ?, rejection_reason = ?, reviewed_at = NOW() 
                WHERE request_id = ? AND doctor_id = ?
            ");
            if ($stmt->execute([$new_status, $reason, $req_id, $doctor_id])) {
                $success_msg = "📄 Medical record release request is now marked as " . htmlspecialchars($new_status) . ".";
            }
        }
    }

    // 3. Fetch Data Access Requests (data_requests)
    $stmtData = $pdo->prepare("
        SELECT 
            dr.id, 
            dr.reason, 
            dr.status, 
            dr.created_at, 
            u.first_name, 
            u.last_name, 
            u.email
        FROM data_requests dr
        JOIN users u ON dr.patient_user_id = u.user_id
        WHERE dr.doctor_id = ?
        ORDER BY dr.created_at DESC
    ");
    $stmtData->execute([$doctor_id]);
    $data_requests = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    // 4. Fetch Medical Record Release Requests (medical_request)
    $stmtMed = $pdo->prepare("
        SELECT 
            mr.request_id,
            mr.request_type,
            mr.description,
            mr.status,
            mr.requested_at,
            mr.rejection_reason,
            u.first_name,
            u.last_name,
            u.email
        FROM medical_request mr
        JOIN patient p ON mr.patient_id = p.patient_id
        JOIN users u ON p.user_id = u.user_id
        WHERE mr.doctor_id = ?
        ORDER BY mr.requested_at DESC
    ");
    $stmtMed->execute([$doctor_id]);
    $medical_requests = $stmtMed->fetchAll(PDO::FETCH_ASSOC);

    // Stats calculations
    $total_data = count($data_requests);
    $total_med = count($medical_requests);
    
    $pending_data = count(array_filter($data_requests, fn($r) => $r['status'] === 'pending'));
    $pending_med = count(array_filter($medical_requests, fn($r) => $r['status'] === 'pending'));

    $approved_data = count(array_filter($data_requests, fn($r) => $r['status'] === 'approved'));
    $completed_med = count(array_filter($medical_requests, fn($r) => $r['status'] === 'completed' || $r['status'] === 'approved'));

} catch (Exception $e) {
    $error_msg = "Clinical Portal Out of Sync: " . $e->getMessage();
    $data_requests = [];
    $medical_requests = [];
    $total_data = $total_med = $pending_data = $pending_med = $approved_data = $completed_med = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unified Request Center | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary: #1b4332;
            --primary-light: #2d6a4f;
            --accent: #40916c;
            --bg-soft: #f0f7f4;
            --surface: #ffffff;
            --danger: #e63946;
            --warning: #f59e0b;
            --success: #16a34a;
            --border: #d0e8e0;
            --text-dark: #1b4332;
            --text-muted: #6b7280;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Quicksand', sans-serif; }
        body { background: var(--bg-soft); color: var(--text-dark); min-height: 100vh; }

        /* Navigation Bar */
        .navbar {
            background-color: var(--primary);
            padding: 12px 60px;
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
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            padding: 10px 18px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            transition: 0.3s;
        }
        .nav-links a:hover, .nav-links a.active { background: rgba(255,255,255,0.1); color: white; }
        .btn-logout { background: var(--danger) !important; color: white !important; font-weight: 700 !important; }

        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }

        /* Stats Cards Dashboard */
        .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { 
            background: var(--surface); 
            padding: 25px; 
            border-radius: 20px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.03); 
            border-left: 6px solid var(--primary-light); 
        }
        .stat-card.warning { border-left-color: var(--warning); }
        .stat-card.success { border-left-color: var(--success); }
        .stat-val { font-size: 32px; font-weight: 700; display: block; margin-top: 5px; color: var(--primary); }
        .stat-label { color: var(--text-muted); font-size: 12px; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; }

        /* Alerts */
        .alert { padding: 15px 20px; border-radius: 12px; margin-bottom: 25px; font-weight: 600; font-size: 14px; }
        .alert-success { background: #e6f9f0; color: var(--success); border: 1.5px solid #c7f3de; }
        .alert-error { background: #ffebeb; color: var(--danger); border: 1.5px solid #fecdd3; }

        /* Modern Tabs Header */
        .tabs-header {
            display: flex;
            gap: 12px;
            margin-bottom: 25px;
            border-bottom: 2px solid var(--border);
            padding-bottom: 8px;
        }

        .tab-btn {
            background: none;
            border: none;
            padding: 12px 24px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            color: var(--text-muted);
            border-radius: 12px;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .tab-btn:hover { background: rgba(45, 106, 79, 0.06); color: var(--primary); }
        .tab-btn.active { background: var(--primary); color: white; }

        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Data Cards */
        .data-card {
            background: var(--surface);
            border-radius: 24px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            border: 1px solid var(--border);
        }

        .card-header-box { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 25px; 
            border-bottom: 1.5px solid var(--border); 
            padding-bottom: 15px; 
        }
        
        .card-header-box h2 { font-size: 1.3rem; color: var(--primary); font-weight: 700; }

        /* Clinical Tables */
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px 20px; font-size: 11px; font-weight: 800; color: var(--text-muted); border-bottom: 2px solid var(--border); text-transform: uppercase; }
        td { padding: 18px 20px; border-bottom: 1px solid #f1f3f5; font-size: 13.5px; font-weight: 600; }
        tr:hover { background: #f8faf9; }

        /* Action Buttons */
        .btn-action-pill {
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            border: none;
            transition: 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-approve-pill { background: #e6f9f0; color: var(--success); border: 1.5px solid #c7f3de; }
        .btn-approve-pill:hover { background: var(--success); color: white; }

        .btn-decline-pill { background: #ffebeb; color: var(--danger); border: 1.5px solid #fecdd3; }
        .btn-decline-pill:hover { background: var(--danger); color: white; }

        .btn-complete-pill { background: #e0f2fe; color: #0369a1; border: 1.5px solid #bae6fd; }
        .btn-complete-pill:hover { background: #0369a1; color: white; }

        /* Badges */
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            display: inline-block;
        }
        .status-pending { background-color: #fff3cd; color: #856404; border: 1.5px solid #ffeeba; }
        .status-approved { background-color: #d1e7dd; color: var(--success); border: 1.5px solid #badbcc; }
        .status-rejected { background-color: #f8d7da; color: var(--danger); border: 1.5px solid #f5c2c7; }
        .status-completed { background-color: #cfe2ff; color: #084298; border: 1.5px solid #b6d4fe; }

        .type-badge {
            background: var(--bg-soft);
            color: var(--primary);
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        /* Glassmorphic Modal */
        .rejection-modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(27, 67, 50, 0.4);
            backdrop-filter: blur(8px);
            align-items: center;
            justify-content: center;
        }

        .modal-card {
            background: var(--surface);
            width: 460px;
            max-width: 90%;
            border-radius: 24px;
            padding: 30px;
            box-shadow: 0 15px 35px rgba(27,67,50,0.15);
            border: 1px solid rgba(255,255,255,0.6);
        }

        .modal-title { font-size: 16px; font-weight: 700; color: var(--primary); margin-bottom: 15px; display: flex; align-items: center; gap: 8px; }
        .reason-textarea { width: 100%; padding: 12px; border-radius: 12px; border: 1.5px solid var(--border); font-family: inherit; font-size: 13px; outline: none; margin-bottom: 18px; resize: none; }
        .reason-textarea:focus { border-color: var(--accent); }
        
        .modal-actions { display: flex; justify-content: flex-end; gap: 10px; }
        .modal-btn { padding: 8px 18px; border-radius: 10px; font-size: 12px; font-weight: 700; cursor: pointer; border: none; transition: 0.2s; }
        .modal-btn-cancel { background: #f3f4f6; color: var(--text-muted); }
        .modal-btn-submit { background: var(--danger); color: white; }
        .modal-btn-submit:hover { opacity: 0.9; }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            header { padding: 12px 20px; }
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
            <a href="doctor-medical-request.php" class="active">Requests Console</a>
            <a href="logout.php" class="btn-logout"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </nav>

    <div class="container">
        <!-- Success/Error Message banners -->
        <?php if ($success_msg): ?>
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?php echo $success_msg; ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo $error_msg; ?></div>
        <?php endif; ?>

        <!-- Stats Dashboard Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-label">Total Requests Received</span>
                <span class="stat-val"><?= ($total_data + $total_med) ?></span>
            </div>
            <div class="stat-card warning">
                <span class="stat-label">Pending Action</span>
                <span class="stat-val"><?= ($pending_data + $pending_med) ?></span>
            </div>
            <div class="stat-card success">
                <span class="stat-label">Approved & Released</span>
                <span class="stat-val"><?= ($approved_data + $completed_med) ?></span>
            </div>
        </div>

        <!-- Sleek Unified Tab triggers -->
        <div class="tabs-header">
            <button id="btn-tab-data" class="tab-btn active" onclick="switchTab('data')"><i class="fa-solid fa-folder-open"></i> Data Access Requests (<?= $total_data ?>)</button>
            <button id="btn-tab-med" class="tab-btn" onclick="switchTab('med')"><i class="fa-solid fa-file-invoice"></i> Record Release Requests (<?= $total_med ?>)</button>
        </div>

        <!-- ==============================================
             TAB 1: DATA ACCESS REQUESTS
             ============================================== -->
        <div id="tab-data" class="tab-content active">
            <div class="data-card">
                <div class="card-header-box">
                    <h2>📂 Patient Record Data Access Requests</h2>
                    <span style="font-size: 11px; background: var(--bg-soft); color: var(--primary); padding: 5px 12px; border-radius: 20px; font-weight: 700;">
                        System Logs Pool
                    </span>
                </div>

                <?php if (count($data_requests) > 0): ?>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Patient Details</th>
                                    <th>Reason for Access</th>
                                    <th>Date Requested</th>
                                    <th>Status</th>
                                    <th style="text-align: center;">Actions Decision</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($data_requests as $req): ?>
                                    <tr>
                                        <td>
                                            <strong style="color: var(--primary); font-size:14.5px;">👤 <?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name']); ?></strong>
                                            <div style="font-size: 11px; color:#888; font-weight:500; margin-top:2px;">✉️ <?= htmlspecialchars($req['email']); ?></div>
                                        </td>
                                        <td style="color: #444; font-style: italic; max-width: 250px; line-height: 1.4;">
                                            "<?= htmlspecialchars($req['reason']); ?>"
                                        </td>
                                        <td>
                                            <span style="font-weight: 700; color: var(--primary); font-size:12.5px;">📅 <?= date('M d, Y', strtotime($req['created_at'])); ?></span>
                                            <span style="display:block; font-size: 11px; color:#888; margin-top:2px;">⏰ <?= date('h:i A', strtotime($req['created_at'])); ?></span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?= htmlspecialchars($req['status']); ?>">
                                                <?= htmlspecialchars($req['status']); ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($req['status'] === 'pending'): ?>
                                                <div style="display:flex; gap:6px; justify-content:center;">
                                                    <form method="POST" style="margin:0;">
                                                        <input type="hidden" name="action" value="decide_data_request">
                                                        <input type="hidden" name="request_id" value="<?= $req['id']; ?>">
                                                        <input type="hidden" name="status" value="approved">
                                                        <button type="submit" class="btn-action-pill btn-approve-pill"><i class="fa-solid fa-check"></i> Grant</button>
                                                    </form>
                                                    <form method="POST" style="margin:0;">
                                                        <input type="hidden" name="action" value="decide_data_request">
                                                        <input type="hidden" name="request_id" value="<?= $req['id']; ?>">
                                                        <input type="hidden" name="status" value="rejected">
                                                        <button type="submit" class="btn-action-pill btn-decline-pill"><i class="fa-solid fa-times"></i> Deny</button>
                                                    </form>
                                                </div>
                                            <?php else: ?>
                                                <span style="font-size:12px; color: var(--text-muted); font-weight:700;"><i class="fa-solid fa-lock-open"></i> Log Resolved</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="text-align:center; padding: 40px 0; color: var(--text-muted);">
                        <i class="fa-regular fa-folder-open" style="font-size: 40px; margin-bottom:12px; color: var(--border);"></i>
                        <p style="font-weight:700;">No record data access requests received.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ==============================================
             TAB 2: MEDICAL RELEASE REQUESTS
             ============================================== -->
        <div id="tab-med" class="tab-content">
            <div class="data-card">
                <div class="card-header-box">
                    <h2>📄 Medical Document Release Requests</h2>
                    <span style="font-size: 11px; background: var(--bg-soft); color: var(--primary); padding: 5px 12px; border-radius: 20px; font-weight: 700;">
                        Release Ledger
                    </span>
                </div>

                <?php if (count($medical_requests) > 0): ?>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>Patient Details</th>
                                    <th>Type</th>
                                    <th>Notes / Reason</th>
                                    <th>Requested Date</th>
                                    <th>Status</th>
                                    <th style="text-align: center;">Actions Decision</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($medical_requests as $req): ?>
                                    <tr>
                                        <td>
                                            <strong style="color: var(--primary); font-size:14.5px;">👤 <?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name']); ?></strong>
                                            <div style="font-size: 11px; color:#888; font-weight:500; margin-top:2px;">✉️ <?= htmlspecialchars($req['email']); ?></div>
                                        </td>
                                        <td>
                                            <span class="type-badge"><?= str_replace('_', ' ', htmlspecialchars($req['request_type'])); ?></span>
                                        </td>
                                        <td style="color: #444; font-style: italic; max-width: 250px; line-height: 1.4;">
                                            "<?= htmlspecialchars($req['description']); ?>"
                                            <?php if ($req['status'] === 'rejected' && $req['rejection_reason']): ?>
                                                <div style="margin-top: 5px; color: var(--danger); font-size: 11px; font-weight: 700;">Reason: "<?= htmlspecialchars($req['rejection_reason']); ?>"</div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="font-weight: 700; color: var(--primary); font-size:12.5px;">📅 <?= date('M d, Y', strtotime($req['requested_at'])); ?></span>
                                            <span style="display:block; font-size: 11px; color:#888; margin-top:2px;">⏰ <?= date('h:i A', strtotime($req['requested_at'])); ?></span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?= htmlspecialchars($req['status']); ?>">
                                                <?= htmlspecialchars($req['status']); ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <?php if ($req['status'] === 'pending'): ?>
                                                <div style="display:flex; gap:6px; justify-content:center;">
                                                    <form method="POST" style="margin:0;">
                                                        <input type="hidden" name="action" value="decide_medical_request">
                                                        <input type="hidden" name="request_id" value="<?= $req['request_id']; ?>">
                                                        <input type="hidden" name="status" value="approved">
                                                        <button type="submit" class="btn-action-pill btn-approve-pill"><i class="fa-solid fa-check"></i> Approve</button>
                                                    </form>
                                                    
                                                    <button type="button" class="btn-action-pill btn-decline-pill" onclick="promptRejection(<?= $req['request_id']; ?>)">
                                                        <i class="fa-solid fa-times"></i> Reject
                                                    </button>
                                                </div>
                                            <?php elseif ($req['status'] === 'approved'): ?>
                                                <form method="POST" style="margin:0;">
                                                    <input type="hidden" name="action" value="decide_medical_request">
                                                    <input type="hidden" name="request_id" value="<?= $req['request_id']; ?>">
                                                    <input type="hidden" name="status" value="completed">
                                                    <button type="submit" class="btn-action-pill btn-complete-pill"><i class="fa-solid fa-file-export"></i> Release Record</button>
                                                </form>
                                            <?php else: ?>
                                                <span style="font-size:12px; color: var(--text-muted); font-weight:700;"><i class="fa-solid fa-circle-check"></i> Archival Closed</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div style="text-align:center; padding: 40px 0; color: var(--text-muted);">
                        <i class="fa-regular fa-file-pdf" style="font-size: 40px; margin-bottom:12px; color: var(--border);"></i>
                        <p style="font-weight:700;">No medical record release requests received.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ==============================================
         REJECTION REASON GLASSMORPHIC MODAL
         ============================================== -->
    <div id="rejectionModal" class="rejection-modal" onclick="closeModalOnBackdrop(event)">
        <div class="modal-card">
            <div class="modal-title">
                <i class="fa-solid fa-circle-question" style="color: var(--danger);"></i> Rejection Decision Audit
            </div>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="decide_medical_request">
                <input type="hidden" name="status" value="rejected">
                <input type="hidden" id="modal-request-id" name="request_id" value="">
                
                <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 12px;">Please specify the official clinic reason for rejecting this document release request:</p>
                <textarea name="rejection_reason" class="reason-textarea" rows="4" placeholder="Type reason (e.g. Requires physically signed authorization letter)..." required></textarea>
                
                <div class="modal-actions">
                    <button type="button" class="modal-btn modal-btn-cancel" onclick="closeRejectionModal()">Cancel</button>
                    <button type="submit" class="modal-btn modal-btn-submit">Confirm Rejection</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ==============================================
         TAB TOGGLERS & REJECTION MODAL HANDLERS
         ============================================== -->
    <script>
        // Smooth local tab navigation switcher
        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            
            if (tabName === 'data') {
                document.getElementById('tab-data').classList.add('active');
                document.getElementById('btn-tab-data').classList.add('active');
                localStorage.setItem('active-request-tab', 'data');
            } else {
                document.getElementById('tab-med').classList.add('active');
                document.getElementById('btn-tab-med').classList.add('active');
                localStorage.setItem('active-request-tab', 'med');
            }
        }

        // Prompt Rejection Audit reason Modal
        function promptRejection(requestId) {
            document.getElementById('modal-request-id').value = requestId;
            const modal = document.getElementById('rejectionModal');
            modal.style.display = 'flex';
        }

        function closeRejectionModal() {
            document.getElementById('rejectionModal').style.display = 'none';
        }

        function closeModalOnBackdrop(event) {
            if (event.target === document.getElementById('rejectionModal')) {
                closeRejectionModal();
            }
        }

        // Restore tab selection state on reload
        window.addEventListener('DOMContentLoaded', () => {
            const activeTab = localStorage.getItem('active-request-tab');
            if (activeTab === 'med') {
                switchTab('med');
            } else {
                switchTab('data');
            }
        });
    </script>
</body>
</html>