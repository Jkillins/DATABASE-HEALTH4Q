<?php
/**
 * assistant-queue.php - Full Premium Clinical Queue Management
 */
require_once 'config.php';
requireRole(ROLE_ASSISTANT);

$pdo = getPDO();

// Ensure patient_queue's appointment_id column is NULLABLE (safeguard against old database migrations)
try {
    $pdo->exec("ALTER TABLE patient_queue MODIFY appointment_id INT NULL");
} catch (Exception $e) {}

$assistant_id = getCurrentRoleId();

$today = date('Y-m-d');
$message = '';
$message_type = 'success';

// 1. Fetch Patients & Doctors for Dropdowns
try {
    $stmtP = $pdo->query("SELECT p.patient_id, u.first_name, u.last_name FROM patient p JOIN users u ON p.user_id = u.user_id ORDER BY u.last_name ASC");
    $patients = $stmtP->fetchAll();

    $stmtD = $pdo->query("SELECT d.doctor_id, u.first_name, u.last_name FROM doctor d JOIN users u ON d.user_id = u.user_id ORDER BY u.last_name ASC");
    $doctors = $stmtD->fetchAll();
} catch (Exception $e) {
    $patients = [];
    $doctors = [];
}

// 2. Handle Queue Status Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $queue_id = (int)$_POST['queue_id'];
    $new_status = sanitize($_POST['status']);
    
    if (in_array($new_status, ['waiting', 'called', 'in-progress', 'completed', 'canceled'])) {
        try {
            $update_data = [];
            if ($new_status === 'called') {
                $update_data = ['status' => $new_status, 'called_time' => date('Y-m-d H:i:s')];
            } elseif ($new_status === 'in-progress') {
                $update_data = ['status' => $new_status, 'seen_time' => date('Y-m-d H:i:s')];
            } elseif ($new_status === 'completed') {
                $update_data = ['status' => $new_status, 'check_out_time' => date('Y-m-d H:i:s')];
            } else {
                $update_data = ['status' => $new_status];
            }

            $set_clause = implode(', ', array_map(fn($k) => "$k = ?", array_keys($update_data)));
            $values = array_values($update_data);
            $values[] = $queue_id;

            $stmt = $pdo->prepare("UPDATE patient_queue SET $set_clause WHERE queue_id = ?");
            if ($stmt->execute($values)) {
                // Trigger Notification to Patient
                $stmtUser = $pdo->prepare("SELECT p.user_id, pq.queue_position FROM patient_queue pq JOIN patient p ON pq.patient_id = p.patient_id WHERE pq.queue_id = ?");
                $stmtUser->execute([$queue_id]);
                $res = $stmtUser->fetch(PDO::FETCH_ASSOC);
                if ($res) {
                    createNotification(
                        $res['user_id'],
                        "Queue Status Updated",
                        "Your queue ticket #" . $res['queue_position'] . " status has been updated to '" . ucfirst($new_status) . "'."
                    );
                }

                $message = "✓ Queue status updated successfully.";
            }
        } catch (Exception $e) {
            $message = "✗ Error: " . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// 3. Handle Queue Check-In Post Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_in'])) {
    $p_id = (int)$_POST['patient_id'];
    $d_id = (int)$_POST['doctor_id'];

    if ($p_id > 0 && $d_id > 0) {
        try {
            // Auto-detect appointment_id for today if exists
            $stmtA = $pdo->prepare("
                SELECT appointment_id FROM appointment 
                WHERE patient_id = ? AND doctor_id = ? AND DATE(schedule_start) = CURDATE() AND status = 'scheduled'
                LIMIT 1
            ");
            $stmtA->execute([$p_id, $d_id]);
            $appt_id = $stmtA->fetchColumn() ?: null;
            
            // Calculate next queue position
            $stmtPos = $pdo->prepare("SELECT COALESCE(MAX(queue_position), 0) + 1 FROM patient_queue WHERE DATE(check_in_time) = CURDATE()");
            $stmtPos->execute();
            $next_position = $stmtPos->fetchColumn();
            
            // Insert into patient_queue
            $stmtIns = $pdo->prepare("
                INSERT INTO patient_queue (patient_id, doctor_id, appointment_id, check_in_time, queue_position, status)
                VALUES (?, ?, ?, NOW(), ?, 'waiting')
            ");
            
            if ($stmtIns->execute([$p_id, $d_id, $appt_id, $next_position])) {
                // Update appointment status to 'in-progress' if found
                if ($appt_id) {
                    $stmtUpApt = $pdo->prepare("UPDATE appointment SET status = 'in-progress' WHERE appointment_id = ?");
                    $stmtUpApt->execute([$appt_id]);
                }

                // Trigger Notification to Patient
                $stmtUser = $pdo->prepare("SELECT user_id FROM patient WHERE patient_id = ?");
                $stmtUser->execute([$p_id]);
                $p_user_id = $stmtUser->fetchColumn();
                if ($p_user_id) {
                    createNotification(
                        $p_user_id,
                        "Checked into Clinic Queue",
                        "You have been checked in successfully! Your queue ticket number is #" . $next_position . ". Please check your live queue status on your dashboard."
                    );
                }

                header("Location: assistant-queue.php?success=1");
                exit;
            }
        } catch (Exception $e) {
            $message = "✗ Error: " . $e->getMessage();
            $message_type = 'error';
        }
    } else {
        $message = "✗ Please select both a patient and an assigned doctor.";
        $message_type = 'error';
    }
}

if (isset($_GET['success'])) {
    $message = "✓ Patient successfully checked in and ticket assigned.";
}

// 4. Get Full Queue for Today
try {
    $stmt = $pdo->prepare('
        SELECT pq.*, 
            u_p.first_name as patient_fname, u_p.last_name as patient_lname, u_p.contact_no,
            u_d.first_name as doctor_fname, u_d.last_name as doctor_lname,
            vt.name as visit_type
        FROM patient_queue pq
        JOIN patient p ON pq.patient_id = p.patient_id
        JOIN users u_p ON p.user_id = u_p.user_id
        JOIN doctor d ON pq.doctor_id = d.doctor_id
        JOIN users u_d ON d.user_id = u_d.user_id
        LEFT JOIN appointment a ON pq.appointment_id = a.appointment_id
        LEFT JOIN visit_type vt ON a.visit_type_id = vt.visit_type_id
        WHERE DATE(pq.check_in_time) = ?
        ORDER BY pq.queue_position ASC
    ');
    $stmt->execute([$today]);
    $queue = $stmt->fetchAll();
} catch (Exception $e) {
    $queue = [];
}

// Statistics
$waiting_count = count(array_filter($queue, fn($q) => $q['status'] === 'waiting'));
$called_count = count(array_filter($queue, fn($q) => $q['status'] === 'called'));
$in_progress_count = count(array_filter($queue, fn($q) => $q['status'] === 'in-progress'));
$completed_count = count(array_filter($queue, fn($q) => $q['status'] === 'completed'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Queue | Health4Q</title>
    <link rel="icon" type="image/png" href="images/Logo_only.png">
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-green: #1a4d34;
            --accent-green: #2d6a4f;
            --light-bg: #c5e6e1;
            --white: #ffffff;
            --text-dark: #1b4332;
            --text-light: #666;
            --border-color: #d0e8e0;
            --danger: #d90429;
            --warning: #f77f00;
            --info: #4a7c2c;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Quicksand', sans-serif;
            background: radial-gradient(circle at center, #d8f3dc 0%, var(--light-bg) 100%);
            min-height: 100vh;
            color: var(--text-dark);
        }

        .top-nav {
            background: var(--primary-green);
            padding: 12px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

        .nav-brand img { height: 40px; filter: brightness(0) invert(1); }
        .nav-links { display: flex; gap: 8px; }
        .nav-links a {
            color: white;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            padding: 8px 15px;
            border-radius: 8px;
            background: rgba(255,255,255,0.1);
            transition: 0.3s;
        }
        .nav-links a:hover, .nav-links a.active { background: var(--accent-green); }

        .logout-btn {
            background: var(--danger);
            color: white;
            padding: 8px 16px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            font-size: 12px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 20px;
        }

        h1 {
            font-size: 28px;
            color: var(--primary-green);
            margin-bottom: 25px;
        }

        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: bold;
            text-align: center;
        }
        .message.success { background: #d4edda; color: #155724; }
        .message.error { background: #f8d7da; color: #721c24; }

        .grid-layout {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 25px;
            align-items: start;
        }

        @media (max-width: 992px) {
            .grid-layout { grid-template-columns: 1fr; }
        }

        .card {
            background: var(--white);
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 6px 20px rgba(27, 67, 50, 0.05);
            margin-bottom: 25px;
            border: 1px solid rgba(255, 255, 255, 0.6);
        }

        .card h3 {
            margin-bottom: 20px;
            font-size: 18px;
            color: var(--primary-green);
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 10px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--accent-green);
            margin-bottom: 6px;
            text-transform: uppercase;
        }

        .input-control {
            width: 100%;
            padding: 12px;
            border: 1.5px solid var(--border-color);
            border-radius: 10px;
            font-family: inherit;
            background: #f9fafb;
            font-size: 13px;
        }

        .input-control:focus {
            outline: none;
            border-color: var(--accent-green);
            background: white;
        }

        .btn-checkin {
            width: 100%;
            background: var(--accent-green);
            color: white;
            border: none;
            padding: 14px;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.3s;
            font-size: 13px;
        }

        .btn-checkin:hover {
            background: var(--primary-green);
            transform: translateY(-1px);
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-box {
            background: var(--white);
            padding: 15px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        .stat-number {
            font-size: 24px;
            font-weight: 700;
            color: var(--accent-green);
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 5px;
        }

        .queue-container {
            background: var(--white);
            border-radius: 12px;
            overflow-x: auto;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: var(--primary-green);
            color: white;
        }

        th {
            padding: 12px 15px;
            text-align: left;
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-color);
            font-size: 13px;
        }

        tbody tr:hover { background: #f9f9f9; }

        .patient-name {
            font-weight: 600;
            color: var(--primary-green);
        }

        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .status-waiting { background: #fff3cd; color: #856404; }
        .status-called { background: #cfe2ff; color: #084298; }
        .status-in-progress { background: #d1ecf1; color: #0c5460; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-canceled { background: #f8d7da; color: #721c24; }

        .status-select {
            padding: 6px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-family: 'Quicksand', sans-serif;
            font-size: 12px;
            cursor: pointer;
        }

        .action-btn {
            padding: 6px 12px;
            background: var(--accent-green);
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 12px;
            cursor: pointer;
            transition: 0.3s;
        }

        .action-btn:hover { background: var(--primary-green); }

        .empty-state {
            padding: 40px;
            text-align: center;
            color: var(--text-light);
        }

        @media (max-width: 768px) {
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            table { font-size: 12px; }
            th, td { padding: 8px 10px; }
        }
    </style>
</head>
<body>
    <div class="top-nav">
        <div class="nav-brand">
            <img src="images/Logo_only.png" alt="Health4Q">
        </div>
        <div class="nav-links">
            <a href="assistant-dashboard.php">Overview</a>
            <a href="assistant-queue.php" class="active">📋 Live Queue</a>
            <a href="assistant-appointments.php">📅 Appointments</a>
            <a href="assistant-broadcast.php">📢 Alerts</a>
            <a href="assistant-referral.php">📤 Referrals</a>
            <a href="assistant-inventory.php">📦 Supplies</a>
            <a href="assistant-patient-search.php">🔍 Search</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>

    <div class="container">
        <h1>📋 Live Queue Management</h1>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <div class="grid-layout">
            <!-- Left Side: Check-in Form -->
            <div class="card">
                <h3>➕ Check-In Patient</h3>
                <form method="POST">
                    <div class="form-group">
                        <label>Select Patient</label>
                        <select name="patient_id" class="input-control" required>
                            <option value="">-- Choose Patient --</option>
                            <?php foreach ($patients as $p): ?>
                                <option value="<?php echo $p['patient_id']; ?>"><?php echo htmlspecialchars($p['last_name'] . ', ' . $p['first_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Assign Doctor</label>
                        <select name="doctor_id" class="input-control" required>
                            <option value="">-- Choose Doctor --</option>
                            <?php foreach ($doctors as $d): ?>
                                <option value="<?php echo $d['doctor_id']; ?>">Dr. <?php echo htmlspecialchars($d['last_name'] . ', ' . $d['first_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" name="check_in" class="btn-checkin">⚡ Generate Ticket & Check In</button>
                </form>
            </div>

            <!-- Right Side: Live Table and Stats -->
            <div>
                <div class="stats-row">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo $waiting_count; ?></div>
                        <div class="stat-label">⏳ Waiting</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number"><?php echo $called_count; ?></div>
                        <div class="stat-label">📢 Called</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number"><?php echo $in_progress_count; ?></div>
                        <div class="stat-label">🔄 Consultation</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number"><?php echo $completed_count; ?></div>
                        <div class="stat-label">✅ Completed</div>
                    </div>
                </div>

                <div class="queue-container">
                    <?php if (count($queue) > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Ticket #</th>
                                    <th>Patient Name</th>
                                    <th>Doctor</th>
                                    <th>Check-In</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($queue as $patient): ?>
                                    <tr>
                                        <td><strong>#<?php echo $patient['queue_position']; ?></strong></td>
                                        <td>
                                            <div class="patient-name"><?php echo htmlspecialchars($patient['patient_fname'] . ' ' . $patient['patient_lname']); ?></div>
                                            <small style="color: #666;"><?php echo htmlspecialchars($patient['contact_no'] ?? 'No Contact'); ?></small>
                                        </td>
                                        <td>Dr. <?php echo htmlspecialchars($patient['doctor_lname']); ?></td>
                                        <td><?php echo date('H:i', strtotime($patient['check_in_time'])); ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower($patient['status']); ?>">
                                                <?php echo ucfirst($patient['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <form method="POST" style="display: flex; gap: 5px;">
                                                <input type="hidden" name="queue_id" value="<?php echo $patient['queue_id']; ?>">
                                                <select name="status" class="status-select">
                                                    <option value="waiting" <?php echo $patient['status'] === 'waiting' ? 'selected' : ''; ?>>Waiting</option>
                                                    <option value="called" <?php echo $patient['status'] === 'called' ? 'selected' : ''; ?>>Called</option>
                                                    <option value="in-progress" <?php echo $patient['status'] === 'in-progress' ? 'selected' : ''; ?>>In Progress</option>
                                                    <option value="completed" <?php echo $patient['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                                    <option value="canceled" <?php echo $patient['status'] === 'canceled' ? 'selected' : ''; ?>>Canceled</option>
                                                </select>
                                                <button type="submit" name="update_status" class="action-btn">Update</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="empty-state">
                            <p>No patients in queue today.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
