<?php
/**
 * doctor-medical-data.php - PROFESSIONAL REPOSITORY
 */
require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. Access Control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
$doctor_user_id = $_SESSION['user_id'];

// Initialize variables
$pending_requests = [];
$history_records = [];
$message = '';
$error = '';

try {
    // Get internal doctor_id
    $stmtDoc = $pdo->prepare("SELECT doctor_id FROM doctor WHERE user_id = ?");
    $stmtDoc->execute([$doctor_user_id]);
    $doctor_id = $stmtDoc->fetchColumn();

    if ($doctor_id) {
        // --- 2. HANDLE REQUEST UPDATES ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_request'])) {
            $req_id = (int)$_POST['request_id'];
            $new_status = $_POST['status']; 
            
            $updateStmt = $pdo->prepare("UPDATE data_requests SET status = ? WHERE id = ? AND doctor_id = ?");
            if ($updateStmt->execute([$new_status, $req_id, $doctor_id])) {
                $message = "Record access " . htmlspecialchars($new_status) . " successfully.";
            }
        }

        // --- 3. FETCH PENDING REQUESTS ---
        $pStmt = $pdo->prepare("
            SELECT dr.id, dr.reason, dr.created_at, u.first_name, u.last_name, u.email
            FROM data_requests dr
            JOIN users u ON dr.patient_user_id = u.user_id
            WHERE dr.doctor_id = ? AND dr.status = 'pending'
            ORDER BY dr.created_at DESC
        ");
        $pStmt->execute([$doctor_id]);
        $pending_requests = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // --- 4. FETCH MEDICAL RECORDS (Columns fixed per image_8dee7f.jpg) ---
    $historyQuery = "
        SELECT 
            mr.record_id as id, 
            mr.created_at as record_date, 
            COALESCE(mr.diagnosis, 'Pending Documentation') as diagnosis, 
            u.first_name, 
            u.last_name,
            u.email,
            DATEDIFF(NOW(), mr.created_at) as days_ago,
            p.patient_id
        FROM medical_record mr 
        JOIN patient p ON mr.patient_id = p.patient_id
        JOIN users u ON p.user_id = u.user_id 
        WHERE mr.created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY)
        ORDER BY mr.created_at DESC
    ";
    $hStmt = $pdo->prepare($historyQuery);
    $hStmt->execute();
    $history_records = $hStmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $error = "Database Sync Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinical Repository & Access Control | Health4Q+</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Premium Clinical Color Palette */
        :root {
            --bg-mint: #d8f3dc; /* Soft mint background */
            --header-green: #1b4332; /* Dark forest green header */
            --accent-green: #2d6a4f;
            --white: #ffffff;
            --logout-red: #d90429;
            --text-dark: #1b4332;
            --light-mint: #e8f5e9;
            --danger-bg: #ffccd5;
            --danger-text: #a4133c;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Quicksand', sans-serif; background-color: var(--bg-mint); color: var(--text-dark); min-height: 100vh; }
        
        /* Cohesive Sticky Navigation Bar */
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

        /* Content Layout */
        .container { max-width: 1100px; margin: 40px auto; padding: 0 20px; }
        
        .welcome-card { 
            background: var(--white); 
            padding: 30px; 
            border-radius: 15px; 
            border-left: 6px solid var(--header-green); 
            margin-bottom: 35px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.03); 
        }
        .welcome-card h1 { margin: 0 0 5px 0; font-size: 1.8rem; color: var(--header-green); font-weight: 700; }
        .welcome-card p { font-size: 14px; opacity: 0.8; }

        /* Section Headings */
        .section-header {
            margin-bottom: 20px;
            border-left: 5px solid var(--accent-green);
            padding-left: 15px;
        }
        .section-header h2 { font-size: 1.4rem; color: var(--header-green); font-weight: 700; }
        .section-header p { font-size: 0.85rem; color: #555; font-weight: 600; margin-top: 2px; }

        /* High-Fidelity Data Cards */
        .data-card {
            background: var(--white);
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.03);
            margin-bottom: 40px;
            border: 1px solid rgba(255,255,255,0.4);
        }

        /* Access Request Items */
        .request-box {
            display: grid;
            grid-template-columns: 1fr auto;
            background: var(--light-mint);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 15px;
            align-items: center;
            border: 1px solid rgba(45, 106, 79, 0.15);
            gap: 15px;
        }
        .request-info strong { font-size: 1.1rem; color: var(--header-green); }
        .request-meta { font-size: 0.85rem; color: #555; font-weight: 600; margin-top: 5px; }

        /* Buttons styling */
        .btn-action {
            display: inline-block;
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 700;
            font-size: 0.85rem;
            text-decoration: none;
            transition: all 0.2s ease;
            text-align: center;
        }
        .btn-approve { background: var(--header-green); color: white; }
        .btn-approve:hover { background: var(--accent-green); transform: translateY(-2px); box-shadow: 0 4px 10px rgba(27,67,50,0.15); }
        
        .btn-decline { background: var(--danger-bg); color: var(--danger-text); margin-left: 10px; }
        .btn-decline:hover { background: #ffb3c1; transform: translateY(-2px); box-shadow: 0 4px 10px rgba(164,19,60,0.15); }

        .btn-analyze {
            color: var(--header-green);
            background: var(--light-mint);
            border: 1px solid rgba(45, 106, 79, 0.2);
            font-weight: 700;
        }
        .btn-analyze:hover {
            background: var(--header-green);
            color: white;
            transform: translateX(3px);
        }

        /* Professional clinical Tables */
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px; border-bottom: 2px solid var(--light-mint); color: #6c757d; font-size: 11px; font-weight: 800; text-transform: uppercase; }
        td { padding: 18px 15px; border-bottom: 1px solid #f1f3f5; font-size: 0.95rem; font-weight: 600; }
        
        .badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            display: inline-block;
        }
        .badge-date { background: var(--light-mint); color: var(--accent-green); }

        /* Notification Alerts */
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-weight: 700;
            font-size: 0.95rem;
            text-align: center;
        }
        .alert-success { background: #b7e4c7; color: #1b4332; border: 1px solid #95d5b2; }
        .alert-error { background: var(--danger-bg); color: var(--danger-text); border: 1px solid #ffb3c1; }

        @media (max-width: 768px) {
            .request-box { grid-template-columns: 1fr; }
            .btn-decline { margin-left: 0; margin-top: 10px; width: 100%; }
            .btn-approve { width: 100%; }
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
            <a href="doctor-medical-data.php" class="active">Medical Data</a>
            <a href="issuance.php">Referrals</a>
            <a href="doctor-prescriptions.php">Prescriptions</a>
            <a href="doctor-lab-orders.php">Lab Orders</a>
            <a href="logout.php" class="btn-logout">Logout</a>
        </div>
    </nav>

    <div class="container">
        <?php if($message): ?> <div class="alert alert-success"><?= $message ?></div> <?php endif; ?>
        <?php if($error): ?> <div class="alert alert-error"><?= $error ?></div> <?php endif; ?>

        <!-- REQUESTS SECTION -->
        <div class="section-header">
            <h2>Access Control Requests</h2>
            <p>Patients requesting specific medical record chart reviews and sharing permissions.</p>
        </div>

        <div class="data-card">
            <?php if($pending_requests): ?>
                <?php foreach($pending_requests as $req): ?>
                <div class="request-box">
                    <div class="request-info">
                        <strong><?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name']) ?></strong>
                        <div class="request-meta">
                            Reason: "<?= htmlspecialchars($req['reason']) ?>" • Requested on <?= date('M d, Y', strtotime($req['created_at'])) ?>
                        </div>
                    </div>
                    <div>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                            <input type="hidden" name="status" value="approved">
                            <button type="submit" name="update_request" class="btn-action btn-approve">APPROVE</button>
                        </form>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                            <!-- FIX: Set value to 'rejected' to align with status ENUM('pending','approved','rejected') -->
                            <input type="hidden" name="status" value="rejected">
                            <button type="submit" name="update_request" class="btn-action btn-decline">DECLINE</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="text-align:center; color:#6c757d; padding: 20px; font-style: italic; font-weight: 600;">No pending access requests at this time.</p>
            <?php endif; ?>
        </div>

        <!-- REPOSITORY SECTION -->
        <div class="section-header">
            <h2>Validated Clinical Repository</h2>
            <p>Historical clinical medical records and diagnosis files recorded in the last 180 days.</p>
        </div>

        <div class="data-card">
            <?php if($history_records): ?>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Patient Entity</th>
                                <th>Recorded Date</th>
                                <th>Primary Diagnosis Preview</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($history_records as $record): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($record['first_name'] . ' ' . $record['last_name']) ?></strong>
                                    <div style="font-size: 0.75rem; color: #6c757d; font-weight: 500;"><?= htmlspecialchars($record['email']) ?></div>
                                </td>
                                <td><span class="badge badge-date"><?= date('F d, Y', strtotime($record['record_date'])) ?></span></td>
                                <td style="color: #495057; font-weight: 500;"><?= htmlspecialchars($record['diagnosis']) ?></td>
                                <td style="text-align:right;">
                                    <!-- FIX: Link correctly to doctor-medical-records.php?patient_id=X instead of non-existent page -->
                                    <a href="doctor-medical-records.php?patient_id=<?= $record['patient_id'] ?>" class="btn-action btn-analyze">
                                        ANALYZE DATA →
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p style="text-align:center; color:#6c757d; padding: 20px; font-style: italic; font-weight: 600;">The repository is currently empty.</p>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>