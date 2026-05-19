<?php
/**
 * patientreqmed.php - Premium Forest Green Patient Request Records Hub
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
            $check = $pdo->prepare("SELECT id FROM data_requests WHERE patient_user_id = ? AND doctor_id = ? AND status = 'pending'");
            $check->execute([$user_id, $doctor_id]);

            if ($check->fetch()) {
                $message = 'You already have a pending request for this physician.';
                $msg_type = 'error';
            } else {
                // Insert into your data_requests table
                $stmt = $pdo->prepare("INSERT INTO data_requests (patient_user_id, doctor_id, reason, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
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
        FROM data_requests dr
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Records | Health4Q</title>
    <link rel="icon" type="image/png" href="images/Logo_only.png">
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root {
            --primary-green: #1a4d34; 
            --light-bg: #c5e6e1;    
            --white: #ffffff;
            --accent-green: #2d6a4f;
            --text-dark: #1b4332;
            --border-color: #e2e8f0;
            --danger: #d90429;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Quicksand', sans-serif; }

        body {
            background: radial-gradient(circle at center, #d8f3dc 0%, var(--light-bg) 100%);
            min-height: 100vh;
            color: var(--text-dark);
            display: flex;
            flex-direction: column;
        }

        /* --- NAVIGATION --- */
        .top-nav {
            background: var(--primary-green);
            padding: 12px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .nav-brand img { height: 40px; filter: brightness(0) invert(1); }

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
        .nav-links a:hover, .nav-links a.active { background: var(--accent-green); }

        .logout-btn {
            background: var(--danger);
            color: white;
            padding: 8px 18px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            font-size: 12px;
            transition: 0.3s;
        }
        .logout-btn:hover { background: #b00220; }

        /* --- CONTAINER --- */
        .container { max-width: 1100px; margin: 40px auto; padding: 0 20px; flex: 1; }

        /* --- HEADER --- */
        .header-section {
            background: var(--white);
            padding: 30px;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            margin-bottom: 30px;
            border-left: 6px solid var(--primary-green);
        }

        .header-section h1 {
            color: var(--primary-green);
            font-size: 1.8rem;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .header-section p {
            color: var(--text-dark);
            opacity: 0.8;
            font-size: 14px;
            font-weight: 500;
        }

        /* --- LAYOUT GRID --- */
        .grid-layout {
            display: grid;
            grid-template-columns: 1.2fr 1.8fr;
            gap: 30px;
        }

        @media (max-width: 900px) {
            .grid-layout {
                grid-template-columns: 1fr;
            }
        }

        /* --- CARDS --- */
        .content-card {
            background: var(--white);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            border: 1px solid rgba(255, 255, 255, 0.4);
        }

        .section-header {
            margin-bottom: 25px;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 12px;
        }

        .section-header h2 {
            font-size: 1.3rem;
            color: var(--primary-green);
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* --- FORM CONTROLS --- */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            color: #555;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .input-box {
            width: 100%;
            padding: 14px;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-dark);
            outline: none;
            transition: all 0.3s ease;
            background: #f8fafc;
        }

        .input-box:focus {
            border-color: var(--accent-green);
            background: white;
            box-shadow: 0 0 0 4px rgba(45, 106, 79, 0.1);
        }

        textarea.input-box {
            resize: none;
        }

        .btn-submit {
            background: var(--primary-green);
            color: white;
            border: none;
            padding: 16px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 700;
            font-size: 1rem;
            width: 100%;
            transition: 0.3s;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
        }

        .btn-submit:hover {
            background: var(--accent-green);
            transform: translateY(-1px);
            box-shadow: 0 5px 15px rgba(26, 77, 46, 0.2);
        }

        /* --- RECENT ACTIVITY TABLE --- */
        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            font-size: 11px;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            padding: 15px 12px;
            border-bottom: 2px solid var(--border-color);
            letter-spacing: 0.5px;
        }

        td {
            padding: 16px 12px;
            font-size: 14.5px;
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover td {
            background: #f8fafc;
        }

        /* --- BADGES --- */
        .badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            display: inline-block;
            letter-spacing: 0.5px;
        }
        .badge-pending { background: #fff3cd; color: #856404; border: 1.5px solid #ffeeba; }
        .badge-approved { background: #d1e7dd; color: #0f5132; border: 1.5px solid #badbcc; }
        .badge-rejected { background: #f8d7da; color: #842029; border: 1.5px solid #f5c2c7; }

        /* --- ALERTS --- */
        .alert {
            padding: 16px;
            border-radius: 12px;
            margin-bottom: 30px;
            font-size: 14px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: #e6f9f0; color: #0f5132; border: 1px solid #c7f3de; }
        .alert-error { background: #ffebeb; color: var(--danger); border: 1px solid #fecdd3; }
    </style>
</head>
<body>

    <!-- Cohesive Forest Green Navigation -->
    <nav class="top-nav">
        <div class="nav-brand">
            <img src="images/Logo_only.png" alt="Health4Q">
        </div>
        <div class="nav-links">
            <a href="patient-dashboard.php">🏠 Dashboard</a>
            <a href="patientprofile.php">👤 Profile</a>
            <a href="patientappoint.php">📅 Appointments</a>
            <a href="patient-prescriptions.php">💊 Prescriptions</a>
            <a href="patient-lab-results.php">🧪 Lab Results</a>
            <a href="patientmedhist.php">📜 History</a>
            <a href="patientreqmed.php" class="active">🔍 Request Records</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </nav>

    <div class="container">
        
        <!-- Welcome Header -->
        <div class="header-section">
            <h1>📂 Request Access to Medical Records</h1>
            <p>Securely request your clinical history and diagnostic logs from your verified Health4Q physicians.</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $msg_type; ?>">
                <?php echo ($msg_type === 'success' ? '✓ ' : '⚠️ ') . htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="grid-layout">
            
            <!-- Request Form Section -->
            <section class="content-card">
                <div class="section-header">
                    <h2><i class="fa-solid fa-file-signature"></i> New Request</h2>
                </div>
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
                        <textarea name="reason" class="input-box" rows="4" placeholder="e.g., Transferring to specialized clinic or personal archiving..." required></textarea>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fa-solid fa-paper-plane"></i> Send Request
                    </button>
                </form>
            </section>

            <!-- Request History Section -->
            <section class="content-card">
                <div class="section-header">
                    <h2><i class="fa-solid fa-clock-rotate-left"></i> Recent Activity</h2>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Physician</th>
                                <th>Date Requested</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($request_history)): ?>
                                <tr>
                                    <td colspan="3" style="text-align: center; color: #64748b; padding: 40px 10px; font-style: italic;">
                                        <i class="fa-regular fa-folder-open" style="font-size: 2.5rem; display: block; margin-bottom: 10px; color: #cbd5e1;"></i>
                                        No record requests submitted yet.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($request_history as $req): ?>
                                    <tr>
                                        <td>
                                            <strong style="color: var(--primary-green);">Dr. <?php echo htmlspecialchars($req['first_name'] . ' ' . $req['last_name']); ?></strong>
                                        </td>
                                        <td>
                                            <span style="font-size: 13px; color: #64748b;">📅 <?php echo date('M d, Y', strtotime($req['created_at'])); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge badge-<?php echo strtolower($req['status']); ?>">
                                                <?php echo htmlspecialchars($req['status']); ?>
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
    </div>

</body>
</html>