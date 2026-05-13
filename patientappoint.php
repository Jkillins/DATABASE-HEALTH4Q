<?php
/**
 * Health4Q - Patient Appointment System
 * Features: Schema-Matched Booking, Dynamic Status Badges, Forest Green UI
 */
require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// 1. Access Control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'patient') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
$user_id = $_SESSION['user_id']; 
$error = ''; 
$success = '';

try {
    // 2. Fetch Patient ID (Crucial for the fk_appointment_patient constraint)
    $stmt = $pdo->prepare('SELECT patient_id FROM patient WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $patient_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $patient_id = $patient_data['patient_id'] ?? null;

    // 3. Database Sync: Ensure Visit Types Exist
    $check_types = $pdo->query("SELECT COUNT(*) FROM visit_type")->fetchColumn();
    if ($check_types == 0) {
        $pdo->exec("INSERT INTO visit_type (visit_type_id, name, description) VALUES
            (1, 'General Checkup', 'Routine physical examination'),
            (2, 'Follow-up Visit', 'Review of results or progress'),
            (3, 'Urgent Care', 'Immediate non-emergency care'),
            (4, 'Consultation', 'Specialist medical advice')");
    }

    // 4. Fetch Doctors for Selection
    $doctors = $pdo->query("SELECT d.doctor_id, u.first_name, u.last_name, d.specialty 
                            FROM doctor d 
                            JOIN users u ON d.user_id = u.user_id 
                            ORDER BY u.last_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    // 5. Fetch Visit Types
    $visit_types = $pdo->query("SELECT * FROM visit_type ORDER BY visit_type_id ASC")->fetchAll(PDO::FETCH_ASSOC);

    // 6. Handle Booking Submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'schedule') {
        $doctor_id = (int)$_POST['doctor_id'];
        $v_type_id = (int)$_POST['visit_type_id'];
        $start_time = $_POST['schedule_start'];
        $notes      = htmlspecialchars(trim($_POST['notes']));

        if ($doctor_id && $v_type_id && $start_time) {
            if (strtotime($start_time) < time()) {
                $error = "Appointment cannot be in the past.";
            } elseif (!$patient_id) {
                $error = "Patient profile not found. Please complete your registration first.";
            } else {
                // Calculation for schedule_end (Default 30 mins)
                $end_time = date('Y-m-d H:i:s', strtotime('+30 minutes', strtotime($start_time)));
                
                // Matches your SQL Schema: patient_id, doctor_id, visit_type_id, schedule_start, schedule_end, status, created_by, notes
                $sql = "INSERT INTO appointment (patient_id, doctor_id, visit_type_id, schedule_start, schedule_end, status, created_by, notes) 
                        VALUES (?, ?, ?, ?, ?, 'scheduled', ?, ?)";
                $stmt = $pdo->prepare($sql);
                
                if ($stmt->execute([$patient_id, $doctor_id, $v_type_id, $start_time, $end_time, $user_id, $notes])) {
                    $success = "Appointment successfully scheduled!";
                } else {
                    $error = "Unable to save appointment. Please try again.";
                }
            }
        } else {
            $error = "Please fill in all required fields.";
        }
    }

    // 7. Fetch Appointment History (Linked to the appointment table)
    $stmt = $pdo->prepare("
        SELECT a.*, u.last_name as doc_lname, vt.name as v_name 
        FROM appointment a 
        JOIN doctor d ON a.doctor_id = d.doctor_id 
        JOIN users u ON d.user_id = u.user_id
        JOIN visit_type vt ON a.visit_type_id = vt.visit_type_id
        WHERE a.patient_id = ? 
        ORDER BY a.schedule_start DESC
    ");
    $stmt->execute([$patient_id]);
    $my_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) { 
    $error = "System Error: " . $e->getMessage(); 
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointments | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a4d2e; --secondary: #4f772d; --bg: #f4f9f4;
            --surface: #ffffff; --text-main: #1c2a1c; --text-muted: #6b7280;
            --border: #e5e7eb; --success: #16a34a; --danger: #dc2626; --warning: #ca8a04;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background-color: var(--bg); color: var(--text-main); line-height: 1.6; }

        .header-nav {
            background: var(--primary); padding: 1rem 5%; display: flex;
            justify-content: space-between; align-items: center;
            position: sticky; top: 0; z-index: 1000; box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .nav-brand img { height: 40px; filter: brightness(0) invert(1); }
        .nav-links a { color: rgba(255,255,255,0.8); text-decoration: none; margin-left: 24px; font-size: 0.9rem; transition: 0.3s; }
        .nav-links a:hover, .nav-links a.active { color: white; font-weight: 600; }

        .container { max-width: 1200px; margin: 40px auto; padding: 0 24px; }
        .page-header { margin-bottom: 32px; }
        .page-header h1 { font-size: 2.2rem; color: var(--primary); font-weight: 800; }

        .main-grid { display: grid; grid-template-columns: 1fr 1.6fr; gap: 32px; align-items: start; }
        @media (max-width: 992px) { .main-grid { grid-template-columns: 1fr; } }

        .card { background: var(--surface); border-radius: 24px; padding: 32px; box-shadow: 0 10px 30px rgba(26,77,46,0.04); border: 1px solid rgba(0,0,0,0.02); }
        .card-title { font-size: 1.25rem; font-weight: 700; margin-bottom: 24px; color: var(--primary); display: flex; align-items: center; gap: 12px; }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 0.85rem; font-weight: 700; margin-bottom: 8px; color: var(--text-main); text-transform: uppercase; }
        
        .input-control {
            width: 100%; padding: 14px 16px; border-radius: 12px; border: 1.5px solid var(--border);
            background: #fdfdfd; font-size: 0.95rem; transition: all 0.3s ease;
        }
        .input-control:focus { outline: none; border-color: var(--secondary); box-shadow: 0 0 0 4px rgba(79, 119, 45, 0.15); }

        .btn-primary {
            width: 100%; background: var(--primary); color: white; padding: 16px;
            border: none; border-radius: 14px; font-weight: 700; cursor: pointer; transition: 0.3s ease;
        }
        .btn-primary:hover { background: var(--secondary); transform: translateY(-2px); }

        .appt-item {
            background: var(--surface); border-radius: 18px; padding: 20px; margin-bottom: 16px;
            border: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center;
        }
        
        /* Status Badges matched to ENUM */
        .status-badge { padding: 6px 14px; border-radius: 100px; font-size: 0.7rem; font-weight: 800; text-transform: uppercase; }
        .status-scheduled { background: #e0f2fe; color: #0369a1; }
        .status-completed { background: #dcfce7; color: #166534; }
        .status-canceled { background: #fee2e2; color: #991b1b; }
        .status-in-progress { background: #fef9c3; color: #854d0e; }

        .alert { padding: 16px; border-radius: 14px; margin-bottom: 24px; font-weight: 600; }
        .alert-success { background: #dcfce7; color: #166534; border-left: 5px solid #22c55e; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 5px solid #ef4444; }
    </style>
</head>
<body>

    <nav class="header-nav">
        <div class="nav-brand"><img src="images/Logo_only.png" alt="Health4Q"></div>  
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
            <h1>Book an Appointment</h1>
            <p style="color: var(--text-muted);">Manage your health schedule with ease.</p>
        </header>

        <?php if($success): ?> <div class="alert alert-success">✅ <?php echo $success; ?></div> <?php endif; ?>
        <?php if($error): ?> <div class="alert alert-error">⚠️ <?php echo $error; ?></div> <?php endif; ?>

        <div class="main-grid">
            <!-- Booking Form -->
            <section class="card">
                <h3 class="card-title">📝 Appointment Details</h3>
                <form action="" method="POST" id="bookingForm">
                    <input type="hidden" name="action" value="schedule">
                    
                    <div class="form-group">
                        <label>Select Doctor</label>
                        <select name="doctor_id" class="input-control" required>
                            <option value="" disabled selected>Choose Physician</option>
                            <?php foreach($doctors as $doc): ?>
                                <option value="<?= $doc['doctor_id'] ?>">Dr. <?= htmlspecialchars($doc['last_name']) ?> (<?= htmlspecialchars($doc['specialty']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Visit Category</label>
                        <select name="visit_type_id" class="input-control" required>
                            <option value="" disabled selected>Select Reason</option>
                            <?php foreach($visit_types as $vt): ?>
                                <option value="<?= $vt['visit_type_id'] ?>"><?= htmlspecialchars($vt['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Preferred Date & Time</label>
                        <input type="datetime-local" name="schedule_start" class="input-control" id="datePicker" required>
                    </div>

                    <div class="form-group">
                        <label>Notes (Optional)</label>
                        <textarea name="notes" class="input-control" rows="3" placeholder="Briefly describe your concern..."></textarea>
                    </div>

                    <button type="submit" class="btn-primary" id="submitBtn">Confirm Booking</button>
                </form>
            </section>

            <!-- Appointment History -->
            <section class="card">
                <h3 class="card-title">📅 Booking History</h3>
                <?php if(!empty($my_appointments)): ?>
                    <?php foreach($my_appointments as $a): ?>
                        <div class="appt-item">
                            <div>
                                <h4 style="color: var(--primary);">Dr. <?= htmlspecialchars($a['doc_lname']) ?></h4>
                                <p style="font-size: 0.85rem; color: var(--text-muted);">
                                    <?= htmlspecialchars($a['v_name']) ?> • <?= date('M d, Y | h:i A', strtotime($a['schedule_start'])) ?>
                                </p>
                            </div>
                            <span class="status-badge status-<?= strtolower($a['status']) ?>">
                                <?= str_replace('-', ' ', $a['status']) ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px;">
                        <p style="color: var(--text-muted);">You have no appointments scheduled yet.</p>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <script>
        // Set local min date to prevent past booking via UI
        const datePicker = document.getElementById('datePicker');
        const now = new Date();
        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
        datePicker.min = now.toISOString().slice(0,16);

        document.getElementById('bookingForm').onsubmit = function() {
            const btn = document.getElementById('submitBtn');
            btn.innerHTML = "Processing...";
            btn.style.opacity = "0.7";
            btn.style.pointerEvents = "none";
        };
    </script>
</body>
</html>