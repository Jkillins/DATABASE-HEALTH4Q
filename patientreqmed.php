<?php
/**
 * listofdoctor.php
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
$message = '';
$msg_type = ''; 

try {
    // 1. Get all doctors for the dropdown
    $stmt = $pdo->query("
        SELECT d.doctor_id, u.first_name, u.last_name, d.specialty 
        FROM doctor d 
        JOIN users u ON d.user_id = u.user_id 
        ORDER BY u.last_name ASC
    ");
    $doctors = $stmt->fetchAll();

    // 2. Handle medical record request submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_records') {
        $doctor_id = (int)$_POST['doctor_id'];
        $reason = trim(htmlspecialchars($_POST['reason'] ?? ''));

        if ($doctor_id > 0 && !empty($reason)) {
            // Check for duplicate pending requests
            $check = $pdo->prepare("SELECT id FROM data_request WHERE patient_user_id = ? AND doctor_id = ? AND status = 'pending'");
            $check->execute([$user_id, $doctor_id]);

            if ($check->fetch()) {
                $message = 'You already have a pending request for this physician.';
                $msg_type = 'error';
            } else {
                // Insert into your data_request table
                $stmt = $pdo->prepare("INSERT INTO data_request (patient_user_id, doctor_id, reason, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
                if ($stmt->execute([$user_id, $doctor_id, $reason])) {
                    $message = 'Medical record request submitted successfully!';
                    $msg_type = 'success';
                }
            }
        } else {
            $message = 'Please select a doctor and provide a valid reason.';
            $msg_type = 'error';
        }
    }

    // 3. Fetch existing requests for this patient to show in a table
    $historyStmt = $pdo->prepare("
        SELECT dr.status, dr.created_at, u.first_name, u.last_name 
        FROM data_request dr
        JOIN doctor d ON dr.doctor_id = d.doctor_id
        JOIN users u ON d.user_id = u.user_id
        WHERE dr.patient_user_id = ?
        ORDER BY dr.created_at DESC
    ");
    $historyStmt->execute([$user_id]);
    $request_history = $historyStmt->fetchAll();

} catch (Exception $e) {
    $message = 'Error: ' . $e->getMessage();
    $msg_type = 'error';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Medical Record Requests | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --slate-800: #1e293b;
            --slate-600: #475569;
            --cyan-600: #0891b2;
            --cyan-700: #0e7490;
            --bg-light: #f8fafc;
            --border: #e2e8f0;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background: var(--bg-light); display: flex; min-height: 100vh; color: var(--slate-800); }

        /* Sidebar */
        .sidebar { width: 260px; background: white; border-right: 1px solid var(--border); display: flex; flex-direction: column; padding: 30px 20px; position: fixed; height: 100vh; }
        .nav-links { flex: 1; margin-top: 40px; }
        .nav-links a { display: flex; align-items: center; padding: 12px 15px; text-decoration: none; color: #64748b; font-weight: 500; border-radius: 10px; margin-bottom: 8px; transition: 0.2s; }
        .nav-links a.active, .nav-links a:hover { background: #ecfeff; color: var(--cyan-600); }

        /* Main Content */
        .main-content { margin-left: 260px; flex: 1; padding: 40px 50px; }
        .content-card { background: white; border-radius: 16px; padding: 30px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); border: 1px solid var(--border); margin-bottom: 30px; }
        .section-header { margin-bottom: 25px; border-bottom: 1px solid var(--border); padding-bottom: 10px; }
        
        /* Form */
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; color: #94a3b8; margin-bottom: 8px; text-transform: uppercase; }
        .input-box { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; transition: 0.2s; background: white; outline: none; }
        .input-box:focus { border-color: var(--cyan-600); box-shadow: 0 0 0 3px rgba(8, 145, 178, 0.1); }
        .btn-submit { background: var(--cyan-600); color: white; border: none; padding: 14px; border-radius: 8px; cursor: pointer; font-weight: 700; width: 100%; transition: 0.3s; }
        .btn-submit:hover { background: var(--cyan-700); transform: translateY(-1px); }

        /* Status Badges */
        .badge { padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-approved { background: #dcfce7; color: #166534; }
        .badge-declined { background: #fee2e2; color: #991b1b; }

        /* History Table */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { text-align: left; font-size: 12px; color: #94a3b8; text-transform: uppercase; padding: 12px; border-bottom: 2px solid var(--bg-light); }
        td { padding: 15px 12px; font-size: 14px; border-bottom: 1px solid var(--border); }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 25px; font-size: 14px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    </style>
</head>
<body>

    <aside class="sidebar">
        <img src="images/Logo_name.png" width="150" alt="Logo">
        <nav class="nav-links">
            <a href="patient-dashboard.php">📊 Dashboard</a>
            <a href="patientprofile.php">👤 My Profile</a>
            <a href="patientappoint.php">📅 Appointments</a>
            <a href="patientmedhist.php">📜 Medical History</a>
            <a href="listofdoctor.php" class="active">🔍 Request Records</a>
        </nav>
        <a href="logout.php" style="color: #ef4444; text-decoration: none; font-weight: 600; font-size: 14px; padding: 10px;">🚪 Logout</a>
    </aside>

    <main class="main-content">
        <header style="margin-bottom: 35px;">
            <h1 style="font-size: 26px;">Medical Records</h1>
            <p style="color: var(--slate-600);">Securely request your clinical history from Health4Q physicians.</p>
        </header>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $msg_type; ?>">
                <?php echo ($msg_type === 'success' ? '✓ ' : '⚠ ') . htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: 1fr 1.5fr; gap: 30px;">
            
            <!-- Request Form -->
            <section>
                <div class="content-card">
                    <div class="section-header"><h3>New Request</h3></div>
                    <form action="" method="POST">
                        <input type="hidden" name="action" value="request_records">
                        
                        <div class="form-group">
                            <label>Target Physician</label>
                            <select name="doctor_id" class="input-box" required>
                                <option value="">-- Choose a physician --</option>
                                <?php foreach ($doctors as $doctor): ?>
                                    <option value="<?php echo $doctor['doctor_id']; ?>">
                                        Dr. <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?> 
                                        (<?php echo htmlspecialchars($doctor['specialty']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Reason for Request</label>
                            <textarea name="reason" class="input-box" rows="4" placeholder="e.g., Transferring to a new clinic or personal archival..." required></textarea>
                        </div>

                        <button type="submit" class="btn-submit">Submit Official Request</button>
                    </form>
                </div>
            </section>

            <!-- Request History -->
            <section>
                <div class="content-card">
                    <div class="section-header"><h3>Recent Activity</h3></div>
                    <table>
                        <thead>
                            <tr>
                                <th>Physician</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($request_history)): ?>
                                <tr><td colspan="3" style="text-align: center; color: #94a3b8; padding: 30px;">No record requests found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($request_history as $req): ?>
                                    <tr>
                                        <td><strong>Dr. <?php echo htmlspecialchars($req['last_name']); ?></strong></td>
                                        <td><?php echo date('M d, Y', strtotime($req['created_at'])); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo $req['status']; ?>">
                                                <?php echo $req['status']; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </main>
</body>
</html>