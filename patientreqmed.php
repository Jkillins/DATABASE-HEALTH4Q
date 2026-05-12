<?php
/**
 * listofdoctor.php / patientrequest.php
 * Professional Slate & Cyan Doctor Directory & Data Request
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
$msg_type = ''; // 'success' or 'error'

try {
    // 1. Get all doctors with their user details
    $stmt = $pdo->query("
        SELECT d.doctor_id, u.first_name, u.last_name, d.specialty, u.email 
        FROM doctor d 
        JOIN users u ON d.user_id = u.user_id 
        ORDER BY u.last_name ASC
    ");
    $doctors = $stmt->fetchAll();

    // 2. Handle medical data request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_data') {
        $doctor_id = (int)$_POST['doctor_id'];
        $reason = htmlspecialchars($_POST['reason'] ?? '');

        if ($doctor_id && !empty($reason)) {
            // Note: Update this query to match your actual request tracking table
            $stmt = $pdo->prepare('INSERT INTO data_requests (patient_user_id, doctor_id, reason, status, created_at) VALUES (?, ?, ?, "pending", NOW())');
            if ($stmt->execute([$user_id, $doctor_id, $reason])) {
                $message = 'Request submitted! The physician will review your request shortly.';
                $msg_type = 'success';
            }
        } else {
            $message = 'Please select a doctor and provide a reason.';
            $msg_type = 'error';
        }
    }
} catch (Exception $e) {
    $message = 'Error: ' . $e->getMessage();
    $msg_type = 'error';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Find Doctors & Request Data | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --slate-800: #1e293b;
            --cyan-600: #0891b2;
            --bg-light: #f8fafc;
            --border: #e2e8f0;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
        body { background: var(--bg-light); display: flex; min-height: 100vh; color: var(--slate-800); }

        /* --- SIDEBAR --- */
        .sidebar {
            width: 260px; background: white; border-right: 1px solid var(--border);
            display: flex; flex-direction: column; padding: 30px 20px; position: fixed; height: 100vh;
        }
        .nav-links { flex: 1; margin-top: 40px; }
        .nav-links a {
            display: flex; align-items: center; padding: 12px 15px;
            text-decoration: none; color: #64748b; font-weight: 500;
            border-radius: 10px; margin-bottom: 8px; transition: 0.2s;
        }
        .nav-links a.active, .nav-links a:hover { background: #ecfeff; color: var(--cyan-600); }

        /* --- MAIN --- */
        .main-content { margin-left: 260px; flex: 1; padding: 40px 50px; }
        
        .content-card {
            background: white; border-radius: 16px; padding: 30px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.03); border: 1px solid var(--border);
            max-width: 800px;
        }

        .section-header { margin-bottom: 25px; border-bottom: 1px solid var(--border); padding-bottom: 10px; }
        .section-header h3 { font-size: 18px; color: var(--slate-800); }

        /* --- FORM --- */
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; color: #94a3b8; margin-bottom: 8px; text-transform: uppercase; }
        
        .input-box {
            width: 100%; padding: 12px; border: 1px solid var(--border);
            border-radius: 8px; font-size: 14px; outline: none; transition: 0.2s;
            background: white;
        }
        .input-box:focus { border-color: var(--cyan-600); box-shadow: 0 0 0 3px rgba(8, 145, 178, 0.1); }

        .btn-submit {
            background: var(--cyan-600); color: white; border: none;
            padding: 14px 28px; border-radius: 8px; cursor: pointer;
            font-weight: 700; font-size: 14px; transition: 0.3s;
            width: 100%;
        }
        .btn-submit:hover { background: #0e7490; transform: translateY(-1px); }

        /* --- ALERTS --- */
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 25px; font-size: 14px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        .doctor-hint { font-size: 12px; color: #64748b; margin-top: 6px; }
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
            <a href="listofdoctor.php" class="active">🔍 Find Doctors</a>
        </nav>
        <a href="logout.php" style="color: #ef4444; text-decoration: none; font-weight: 600; font-size: 14px; padding: 10px;">🚪 Logout</a>
    </aside>

    <main class="main-content">
        <header style="margin-bottom: 35px;">
            <h1 style="font-size: 26px;">Clinical Directory</h1>
            <p style="color: #64748b;">Select a physician to request digital medical records or history transfers.</p>
        </header>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $msg_type; ?>">
                <?php echo ($msg_type === 'success' ? '✓ ' : '⚠ ') . htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="content-card">
            <div class="section-header">
                <h3>Submit Data Request</h3>
            </div>
            
            <form action="" method="POST">
                <input type="hidden" name="action" value="request_data">
                
                <div class="form-group">
                    <label>Target Physician</label>
                    <select name="doctor_id" class="input-box" required>
                        <option value="">-- Choose a physician from the directory --</option>
                        <?php foreach ($doctors as $doctor): ?>
                            <option value="<?php echo $doctor['doctor_id']; ?>">
                                Dr. <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?> 
                                (<?php echo htmlspecialchars($doctor['specialty']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="doctor-hint">Only verified Health4Q physicians appear in this list.</p>
                </div>

                <div class="form-group">
                    <label>Purpose of Request</label>
                    <textarea name="reason" class="input-box" rows="5" placeholder="e.g., Transferring to a new clinic, personal record keeping, or second opinion..." required></textarea>
                </div>

                <div style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <p style="font-size: 12px; color: #64748b; line-height: 1.5;">
                        <strong>Privacy Note:</strong> By submitting this request, you authorize the selected physician to review your basic profile information to fulfill the data release.
                    </p>
                </div>

                <button type="submit" class="btn-submit">Send Official Request</button>
            </form>
        </div>
    </main>

</body>
</html>