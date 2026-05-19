<?php
/**
 * assistant-appointments.php - Premium Appointments Directory & Reminder System for Clinical Assistants
 */
require_once 'config.php';
requireRole(ROLE_ASSISTANT);

$pdo = getPDO();
$assistant_id = getCurrentRoleId();
$today = date('Y-m-d');

$message = '';
$message_type = 'success';

// Ensure patient_queue's appointment_id column is NULLABLE
try {
    $pdo->exec("ALTER TABLE patient_queue MODIFY appointment_id INT NULL");
} catch (Exception $e) {}

// Handle notification sending action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_reminder'])) {
    $appointment_id = (int)$_POST['appointment_id'];
    try {
        // Fetch appointment details
        $stmt = $pdo->prepare("
            SELECT a.*, u_p.user_id, u_p.first_name as pfname, u_p.last_name as plname, u_d.last_name as dlname
            FROM appointment a
            JOIN patient p ON a.patient_id = p.patient_id
            JOIN users u_p ON p.user_id = u_p.user_id
            JOIN doctor d ON a.doctor_id = d.doctor_id
            JOIN users u_d ON d.user_id = u_d.user_id
            WHERE a.appointment_id = ?
        ");
        $stmt->execute([$appointment_id]);
        $appt = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($appt) {
            $formatted_time = date('h:i A', strtotime($appt['schedule_start']));
            $formatted_date = date('M d, Y', strtotime($appt['schedule_start']));
            
            $subject = "📅 Appointment Reminder: Dr. " . $appt['dlname'];
            $body = "Hi " . $appt['pfname'] . ", this is a friendly reminder of your scheduled appointment with Dr. " . $appt['dlname'] . " on " . $formatted_date . " at " . $formatted_time . ". Please arrive 15 minutes before your schedule.";

            // Create notification
            createNotification($appt['user_id'], $subject, $body);

            $message = "✓ Reminder notification successfully sent to " . htmlspecialchars($appt['pfname'] . ' ' . $appt['plname']) . "!";
            $message_type = 'success';
        } else {
            $message = "✗ Appointment not found.";
            $message_type = 'error';
        }
    } catch (Exception $e) {
        $message = "✗ Error: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Handle Check-in from appointment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_in_appt'])) {
    $appointment_id = (int)$_POST['appointment_id'];
    try {
        $pdo->beginTransaction();

        // Get appointment details
        $stmt = $pdo->prepare("SELECT * FROM appointment WHERE appointment_id = ?");
        $stmt->execute([$appointment_id]);
        $appt = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($appt) {
            $p_id = $appt['patient_id'];
            $d_id = $appt['doctor_id'];

            // Check if already checked in today
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM patient_queue WHERE patient_id = ? AND DATE(check_in_time) = CURDATE() AND status != 'canceled'");
            $stmtCheck->execute([$p_id]);
            $already_checked_in = $stmtCheck->fetchColumn() > 0;

            if ($already_checked_in) {
                $message = "⚠️ Patient is already checked into the queue today.";
                $message_type = 'error';
                $pdo->rollBack();
            } else {
                // Calculate next queue position
                $stmtPos = $pdo->query("SELECT COALESCE(MAX(queue_position), 0) + 1 FROM patient_queue WHERE DATE(check_in_time) = CURDATE()");
                $next_position = $stmtPos->fetchColumn();

                // Insert into patient_queue
                $stmtQueue = $pdo->prepare("
                    INSERT INTO patient_queue (patient_id, doctor_id, appointment_id, check_in_time, queue_position, status)
                    VALUES (?, ?, ?, NOW(), ?, 'waiting')
                ");
                $stmtQueue->execute([$p_id, $d_id, $appointment_id, $next_position]);

                // Update appointment status to 'in-progress'
                $stmtUpdateAppt = $pdo->prepare("UPDATE appointment SET status = 'in-progress' WHERE appointment_id = ?");
                $stmtUpdateAppt->execute([$appointment_id]);

                // Trigger Notification
                $stmtUser = $pdo->prepare("SELECT user_id FROM patient WHERE patient_id = ?");
                $stmtUser->execute([$p_id]);
                $p_user_id = $stmtUser->fetchColumn();
                if ($p_user_id) {
                    createNotification(
                        $p_user_id,
                        "Checked into Clinic Queue",
                        "You have been checked in successfully from your appointment! Your ticket number is #" . $next_position . ". Monitor your live position on the dashboard."
                    );
                }

                $pdo->commit();
                $message = "✓ Patient checked into today's queue successfully (Ticket #" . $next_position . ")!";
                $message_type = 'success';
            }
        } else {
            $message = "✗ Appointment not found.";
            $message_type = 'error';
            $pdo->rollBack();
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = "✗ Error: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Fetch Today's Appointments
try {
    $stmtToday = $pdo->prepare("
        SELECT a.*, 
               u_p.first_name as patient_fname, u_p.last_name as patient_lname, u_p.contact_no,
               u_d.first_name as doctor_fname, u_d.last_name as doctor_lname,
               vt.name as visit_type
        FROM appointment a
        JOIN patient p ON a.patient_id = p.patient_id
        JOIN users u_p ON p.user_id = u_p.user_id
        JOIN doctor d ON a.doctor_id = d.doctor_id
        JOIN users u_d ON d.user_id = u_d.user_id
        LEFT JOIN visit_type vt ON a.visit_type_id = vt.visit_type_id
        WHERE DATE(a.schedule_start) = ?
        ORDER BY a.schedule_start ASC
    ");
    $stmtToday->execute([$today]);
    $today_appts = $stmtToday->fetchAll(PDO::FETCH_ASSOC);

    // Fetch All / Upcoming Appointments
    $stmtUpcoming = $pdo->prepare("
        SELECT a.*, 
               u_p.first_name as patient_fname, u_p.last_name as patient_lname, u_p.contact_no,
               u_d.first_name as doctor_fname, u_d.last_name as doctor_lname,
               vt.name as visit_type
        FROM appointment a
        JOIN patient p ON a.patient_id = p.patient_id
        JOIN users u_p ON p.user_id = u_p.user_id
        JOIN doctor d ON a.doctor_id = d.doctor_id
        JOIN users u_d ON d.user_id = u_d.user_id
        LEFT JOIN visit_type vt ON a.visit_type_id = vt.visit_type_id
        WHERE DATE(a.schedule_start) > ?
        ORDER BY a.schedule_start ASC
        LIMIT 50
    ");
    $stmtUpcoming->execute([$today]);
    $upcoming_appts = $stmtUpcoming->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $today_appts = [];
    $upcoming_appts = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinic Appointments | Health4Q</title>
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
            --info: #4361ee;
            --success: #16a34a;
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

        /* Tabs System */
        .tabs-header {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 8px;
        }

        .tab-btn {
            background: rgba(255,255,255,0.7);
            border: 1px solid var(--border-color);
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-family: inherit;
            font-size: 13px;
            font-weight: 700;
            color: var(--primary-green);
            transition: 0.3s;
        }

        .tab-btn.active {
            background: var(--accent-green);
            color: white;
            border-color: var(--accent-green);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .queue-container {
            background: var(--white);
            border-radius: 12px;
            overflow-x: auto;
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

        .status-pending { background: #fff3cd; color: #856404; }
        .status-scheduled { background: #e0f2fe; color: #0369a1; }
        .status-in-progress { background: #d1ecf1; color: #0c5460; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-canceled { background: #f8d7da; color: #721c24; }

        .action-flex {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .reminder-btn {
            background: var(--info);
            color: white;
            border: none;
            padding: 8px 14px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 11px;
            cursor: pointer;
            transition: 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .reminder-btn:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
        }

        .checkin-btn {
            background: var(--accent-green);
            color: white;
            border: none;
            padding: 8px 14px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 11px;
            cursor: pointer;
            transition: 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .checkin-btn:hover {
            background: var(--primary-green);
            transform: translateY(-1px);
        }

        .empty-state {
            padding: 40px;
            text-align: center;
            color: var(--text-light);
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
            <a href="assistant-queue.php">📋 Live Queue</a>
            <a href="assistant-appointments.php" class="active">📅 Appointments</a>
            <a href="assistant-broadcast.php">📢 Alerts</a>
            <a href="assistant-referral.php">📤 Referrals</a>
            <a href="assistant-inventory.php">📦 Supplies</a>
            <a href="assistant-patient-search.php">🔍 Search</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>

    <div class="container">
        <h1>📅 Patient Appointments Directory</h1>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>"><?php echo $message; ?></div>
        <?php endif; ?>

        <!-- Tabs System -->
        <div class="tabs-header">
            <button class="tab-btn active" onclick="switchTab(event, 'today-tab')">📅 Today's Appointments (<?php echo count($today_appts); ?>)</button>
            <button class="tab-btn" onclick="switchTab(event, 'upcoming-tab')">⏳ Upcoming Schedule (<?php echo count($upcoming_appts); ?>)</button>
        </div>

        <!-- Today's Appointments Tab -->
        <div id="today-tab" class="tab-content active card">
            <h3>📋 Scheduled For Today</h3>
            <div class="queue-container">
                <?php if (count($today_appts) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Schedule</th>
                                <th>Patient Name</th>
                                <th>Assigned Doctor</th>
                                <th>Visit Type</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($today_appts as $appt): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo date('h:i A', strtotime($appt['schedule_start'])); ?></strong><br>
                                        <small style="color: #666;"><?php echo date('M d, Y'); ?></small>
                                    </td>
                                    <td>
                                        <div class="patient-name"><?php echo htmlspecialchars($appt['patient_fname'] . ' ' . $appt['patient_lname']); ?></div>
                                        <small style="color: #666;"><?php echo htmlspecialchars($appt['contact_no'] ?? 'No Contact'); ?></small>
                                    </td>
                                    <td>Dr. <?php echo htmlspecialchars($appt['doctor_lname'] . ', ' . $appt['doctor_fname']); ?></td>
                                    <td><span style="font-weight: 600; color: var(--accent-green);"><?php echo htmlspecialchars($appt['visit_type'] ?? 'General Consultation'); ?></span></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($appt['status']); ?>">
                                            <?php echo ucfirst($appt['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-flex">
                                            <?php if ($appt['status'] === 'scheduled' || $appt['status'] === 'pending'): ?>
                                                <!-- Send Reminder -->
                                                <form method="POST" style="margin: 0;">
                                                    <input type="hidden" name="appointment_id" value="<?php echo $appt['appointment_id']; ?>">
                                                    <button type="submit" name="send_reminder" class="reminder-btn">
                                                        🔔 Send Reminder
                                                    </button>
                                                </form>

                                                <!-- Check-in to Queue -->
                                                <form method="POST" style="margin: 0;">
                                                    <input type="hidden" name="appointment_id" value="<?php echo $appt['appointment_id']; ?>">
                                                    <button type="submit" name="check_in_appt" class="checkin-btn">
                                                        ⚡ Queue Check-In
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: #888; font-size: 11px; font-weight: 700;">No actions available</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <p>No appointments scheduled for today.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Upcoming Appointments Tab -->
        <div id="upcoming-tab" class="tab-content card">
            <h3>⏳ Upcoming Appointments (Next 50)</h3>
            <div class="queue-container">
                <?php if (count($upcoming_appts) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Schedule</th>
                                <th>Patient Name</th>
                                <th>Assigned Doctor</th>
                                <th>Visit Type</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcoming_appts as $appt): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo date('M d, Y', strtotime($appt['schedule_start'])); ?></strong><br>
                                        <small style="color: #666;"><?php echo date('h:i A', strtotime($appt['schedule_start'])); ?></small>
                                    </td>
                                    <td>
                                        <div class="patient-name"><?php echo htmlspecialchars($appt['patient_fname'] . ' ' . $appt['patient_lname']); ?></div>
                                        <small style="color: #666;"><?php echo htmlspecialchars($appt['contact_no'] ?? 'No Contact'); ?></small>
                                    </td>
                                    <td>Dr. <?php echo htmlspecialchars($appt['doctor_lname'] . ', ' . $appt['doctor_fname']); ?></td>
                                    <td><span style="font-weight: 600; color: var(--accent-green);"><?php echo htmlspecialchars($appt['visit_type'] ?? 'General Consultation'); ?></span></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower($appt['status']); ?>">
                                            <?php echo ucfirst($appt['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-flex">
                                            <?php if ($appt['status'] === 'scheduled' || $appt['status'] === 'pending'): ?>
                                                <form method="POST" style="margin: 0;">
                                                    <input type="hidden" name="appointment_id" value="<?php echo $appt['appointment_id']; ?>">
                                                    <button type="submit" name="send_reminder" class="reminder-btn">
                                                        🔔 Send Reminder
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color: #888; font-size: 11px; font-weight: 700;">No actions available</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <p>No upcoming appointments found.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <script>
        function switchTab(evt, tabId) {
            // Hide all tab contents
            const contents = document.getElementsByClassName("tab-content");
            for (let i = 0; i < contents.length; i++) {
                contents[i].classList.remove("active");
            }

            // Remove active class from all buttons
            const buttons = document.getElementsByClassName("tab-btn");
            for (let i = 0; i < buttons.length; i++) {
                buttons[i].classList.remove("active");
            }

            // Show selected tab content and add active class to button
            document.getElementById(tabId).classList.add("active");
            evt.currentTarget.classList.add("active");
        }
    </script>
</body>
</html>
