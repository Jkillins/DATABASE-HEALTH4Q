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
            DATEDIFF(NOW(), mr.created_at) as days_ago
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
    <title>Clinical Intelligence | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #0f4c3a;
            --accent: #2ecc71;
            --glass: rgba(255, 255, 255, 0.9);
            --shadow: 0 8px 32px rgba(0,0,0,0.08);
            --text: #2c3e50;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            color: var(--text);
            margin: 0;
        }

        /* Nav Branding */
        .top-nav {
            background: var(--primary);
            padding: 1rem 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .top-nav a {
            color: white;
            text-decoration: none;
            font-weight: 600;
            margin: 0 15px;
            font-size: 0.9rem;
            transition: 0.3s;
        }

        .top-nav a:hover { opacity: 0.7; }

        .container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px;
        }

        /* Modern Cards */
        .section-header {
            margin-bottom: 25px;
            border-left: 5px solid var(--accent);
            padding-left: 15px;
        }

        .data-card {
            background: var(--glass);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: var(--shadow);
            border: 1px solid rgba(255,255,255,0.3);
        }

        /* Request Items */
        .request-box {
            display: grid;
            grid-template-columns: 1fr auto;
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 15px;
            align-items: center;
            border: 1px solid #edf2f7;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
            transition: 0.2s;
        }

        .btn-approve { background: var(--accent); color: white; }
        .btn-decline { background: #e74c3c; color: white; margin-left: 10px; }
        .btn:hover { transform: translateY(-2px); filter: brightness(1.1); }

        /* Professional Table */
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 15px; border-bottom: 2px solid #edf2f7; color: #7f8c8d; font-size: 0.8rem; text-transform: uppercase; }
        td { padding: 18px 15px; border-bottom: 1px solid #edf2f7; font-size: 0.95rem; }
        
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .badge-date { background: #ebf8ff; color: #3182ce; }
        .nav-brand img { height: 40px; filter: brightness(0) invert(1); }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 600;
            text-align: center;
        }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>

<nav class="top-nav">
        <div class="nav-brand"><img src="images/Logo_only.png" alt="Health4Q"></div>
        <div class="nav-links">
            <a href="doctor-dashboard.php">Dashboard</a>
            <a href="doctor-profile.php" class="active">Profile</a>
            <a href="doctor-appointment.php">Appointments</a>
            <a href="doctor-medical-data.php">Medical Data</a>
        </div>
</nav>

<div class="container">
    <?php if($message): ?> <div class="alert alert-success"><?= $message ?></div> <?php endif; ?>
    <?php if($error): ?> <div class="alert alert-error"><?= $error ?></div> <?php endif; ?>

    <!-- REQUESTS SECTION -->
    <div class="section-header">
        <h2 style="margin:0;">Access Control Requests</h2>
        <p style="margin:5px 0; color:#666; font-size:0.9rem;">Patients requesting specific chart reviews.</p>
    </div>

    <div class="data-card">
        <?php if($pending_requests): ?>
            <?php foreach($pending_requests as $req): ?>
            <div class="request-box">
                <div>
                    <strong style="font-size: 1.1rem; color: var(--primary);">
                        <?= htmlspecialchars($req['first_name'] . ' ' . $req['last_name']) ?>
                    </strong>
                    <div style="color: #7f8c8d; font-size: 0.85rem; margin-top: 4px;">
                        Reason: "<?= htmlspecialchars($req['reason']) ?>" • <?= date('M d, Y', strtotime($req['created_at'])) ?>
                    </div>
                </div>
                <div>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                        <input type="hidden" name="status" value="approved">
                        <button type="submit" name="update_request" class="btn btn-approve">APPROVE</button>
                    </form>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                        <input type="hidden" name="status" value="declined">
                        <button type="submit" name="update_request" class="btn btn-decline">DECLINE</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="text-align:center; color:#95a5a6; padding: 20px;">No pending access requests at this time.</p>
        <?php endif; ?>
    </div>

    <!-- REPOSITORY SECTION -->
    <div class="section-header">
        <h2 style="margin:0;">Validated Clinical Records</h2>
        <p style="margin:5px 0; color:#666; font-size:0.9rem;">Historical clinical data from the last 180 days.</p>
    </div>

    <div class="data-card">
        <?php if($history_records): ?>
            <table>
                <thead>
                    <tr>
                        <th>Patient Entity</th>
                        <th>Created Date</th>
                        <th>Primary Diagnosis Preview</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($history_records as $record): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($record['first_name'] . ' ' . $record['last_name']) ?></strong>
                            <div style="font-size: 0.75rem; color: #95a5a6;"><?= htmlspecialchars($record['email']) ?></div>
                        </td>
                        <td><span class="badge badge-date"><?= date('F d, Y', strtotime($record['record_date'])) ?></span></td>
                        <td style="color: #555;"><?= htmlspecialchars($record['diagnosis']) ?></td>
                        <td style="text-align:right;">
                            <a href="view-medical-record.php?id=<?= $record['id'] ?>" 
                               style="color: var(--accent); font-weight: 700; text-decoration: none; font-size: 0.85rem;">
                                ANALYZE DATA →
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="text-align:center; color:#95a5a6; padding: 20px;">The repository is currently empty.</p>
        <?php endif; ?>
    </div>
</div>

</body>
</html>