<?php
/**
 * doctor-appointment.php
 */
require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Access Control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
// Note: Using session user_id or a helper to get doctor_id based on your config
$doctor_id = $_SESSION['user_id']; 

$error = '';
$success = '';

// Handle appointment status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $appointment_id = (int)($_POST['appointment_id'] ?? 0);
        $status = htmlspecialchars($_POST['status'] ?? '');
        $notes = htmlspecialchars($_POST['notes'] ?? '');

        if (!in_array($status, ['scheduled', 'completed', 'canceled'])) {
            throw new Exception('Invalid status selected.');
        }

        $stmt = $pdo->prepare('UPDATE appointment SET status = ?, notes = ? WHERE appointment_id = ?');
        $stmt->execute([$status, $notes, $appointment_id]);

        $success = 'Appointment status updated successfully!';
    } catch (Exception $e) {
        $error = 'Update failed: ' . $e->getMessage();
    }
}

// Fetch Appointments
// Adjusting join to match your standard schema (users + patient + appointment)
try {
    $stmt = $pdo->prepare("
        SELECT a.*, u.first_name, u.last_name 
        FROM appointment a
        JOIN patient p ON a.patient_id = p.patient_id
        JOIN users u ON p.user_id = u.user_id
        WHERE a.doctor_id = (SELECT doctor_id FROM doctor WHERE user_id = ?)
        ORDER BY a.schedule_start ASC
    ");
    $stmt->execute([$doctor_id]);
    $appointments = $stmt->fetchAll();
} catch (Exception $e) {
    $appointments = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Appointments | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-green: #1a4d34;
            --light-bg: #c5e6e1;
            --white: #ffffff;
            --accent-green: #2d6a4f;
            --text-dark: #1b4332;
            --status-orange: #f59e0b;
            --status-red: #ef4444;
            --status-green: #10b981;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Quicksand', sans-serif; }

        body {
            background: radial-gradient(circle at center, #d8f3dc 0%, var(--light-bg) 100%);
            min-height: 100vh;
            color: var(--text-dark);
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
            color: white; text-decoration: none; font-size: 13px; font-weight: 500;
            padding: 8px 15px; border-radius: 8px; background: rgba(255,255,255,0.1); margin-right: 10px;
        }
        .nav-links a.active { background: var(--accent-green); }
        .logout-btn { background: #d90429; color: white; padding: 8px 18px; border-radius: 5px; text-decoration: none; font-weight: bold; font-size: 12px; }

        .container { max-width: 900px; margin: 40px auto; padding: 0 20px; }

        /* --- ALERTS --- */
        .alert { padding: 15px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; font-weight: 600; }
        .alert-success { background: #dcfce7; color: #15803d; }
        .alert-error { background: #fee2e2; color: #b91c1c; }

        /* --- APPOINTMENT CARDS --- */
        .apt-card {
            background: var(--white);
            border-radius: 20px;
            margin-bottom: 25px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
            overflow: hidden;
            border: 1px solid rgba(0,0,0,0.03);
        }

        .apt-header {
            padding: 20px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #f0f0f0;
        }

        .patient-info h3 { font-size: 18px; font-weight: 800; color: var(--primary-green); }
        
        .status-pill {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .status-scheduled { background: #fffbeb; color: var(--status-orange); }
        .status-completed { background: #ecfdf5; color: var(--status-green); }
        .status-canceled { background: #fef2f2; color: var(--status-red); }

        .apt-body { padding: 25px; display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }

        .info-bit label { display: block; font-size: 11px; font-weight: 700; color: #999; text-transform: uppercase; margin-bottom: 4px; }
        .info-bit p { font-size: 14px; font-weight: 600; color: var(--text-dark); }

        /* --- UPDATE FORM --- */
        .update-section {
            background: #f8fafc;
            padding: 20px 25px;
            border-top: 1px solid #eee;
        }

        .form-row { display: flex; gap: 15px; align-items: flex-end; }
        .form-group { flex: 1; }
        
        select, textarea {
            width: 100%; padding: 10px; border-radius: 8px; border: 2px solid #e2e8f0;
            font-size: 13px; font-weight: 600; outline: none;
        }
        
        textarea { height: 40px; transition: height 0.3s; }
        textarea:focus { height: 80px; border-color: var(--accent-green); }

        .update-btn {
            background: var(--primary-green);
            color: white; border: none; padding: 12px 20px; border-radius: 8px;
            font-weight: 700; cursor: pointer; font-size: 12px; transition: 0.3s;
        }
        .update-btn:hover { background: var(--accent-green); }

        .no-data { text-align: center; padding: 50px; color: #888; }
    </style>
</head>
<body>

    <nav class="top-nav">
        <div class="nav-brand">
            <img src="images/Logo_only.png" alt="Health4Q">
        </div>
        <div class="nav-links">
            <a href="doctor-dashboard.php">Dashboard</a>
            <a href="doctor-profile.php">Profile</a>
            <a href="doctor-appointment.php" class="active">Appointments</a>
            <a href="doctor-medical-data.php">Medical Data</a>
            <a href="issuance.php">Referrals</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </nav>

    <div class="container">
        <div style="margin-bottom: 30px;">
            <h1 style="color: var(--primary-green); font-weight: 800;">Appointments</h1>
            <p style="color: #666; font-size: 14px;">Manage your daily schedule and update patient statuses.</p>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <?php if (count($appointments) > 0): ?>
            <?php foreach ($appointments as $apt): ?>
                <div class="apt-card">
                    <div class="apt-header">
                        <div class="patient-info">
                            <h3>👤 <?php echo htmlspecialchars($apt['first_name'] . ' ' . $apt['last_name']); ?></h3>
                        </div>
                        <span class="status-pill status-<?php echo $apt['status']; ?>">
                            <?php echo $apt['status']; ?>
                        </span>
                    </div>

                    <div class="apt-body">
                        <div class="info-bit">
                            <label>Date & Time</label>
                            <p>📅 <?php echo date('M d, Y | g:i A', strtotime($apt['schedule_start'])); ?></p>
                        </div>
                        <div class="info-bit">
                            <label>Appointment Type</label>
                            <p>🏥 General Consultation</p>
                        </div>
                        <div class="info-bit" style="grid-column: span 2;">
                            <label>Patient Notes</label>
                            <p><?php echo !empty($apt['notes']) ? htmlspecialchars($apt['notes']) : 'No notes provided.'; ?></p>
                        </div>
                    </div>

                    <?php if ($apt['status'] !== 'completed' && $apt['status'] !== 'canceled'): ?>
                    <div class="update-section">
                        <form method="POST" action="">
                            <input type="hidden" name="appointment_id" value="<?php echo $apt['appointment_id']; ?>">
                            <input type="hidden" name="action" value="update">
                            <div class="form-row">
                                <div class="form-group" style="flex: 0 0 150px;">
                                    <label>Action</label>
                                    <select name="status">
                                        <option value="scheduled">Scheduled</option>
                                        <option value="completed">Mark Completed</option>
                                        <option value="canceled">Cancel</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Doctor's Remarks</label>
                                    <textarea name="notes" placeholder="Add diagnosis or follow-up notes..."></textarea>
                                </div>
                                <button type="submit" class="update-btn">Save</button>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="apt-card no-data">
                <p>No appointments found in your schedule.</p>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>