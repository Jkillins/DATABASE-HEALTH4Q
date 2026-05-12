<?php
/**
 * patientprofile.php - Enhanced Forest Green Theme
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

try {
    // 1. Fetch Patient & User Data
    $stmt = $pdo->prepare('SELECT u.*, p.* FROM users u JOIN patient p ON u.user_id = p.user_id WHERE u.user_id = ?');
    $stmt->execute([$user_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2. Handle Personal Profile Update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_personal') {
        $phone = $_POST['phone'] ?? '';
        $address = $_POST['address'] ?? '';
        $emergency = $_POST['emergency_contact'] ?? '';

        $stmt = $pdo->prepare('UPDATE patient SET phone = ?, address = ?, emergency_contact = ? WHERE user_id = ?');
        if ($stmt->execute([$phone, $address, $emergency, $user_id])) {
            header("Location: patientprofile.php?success=1");
            exit;
        }
    }
} catch (Exception $e) {
    $message = 'Error: ' . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1a4d2e; /* Forest Green */
            --secondary: #4f772d; /* Olive Green */
            --bg: #f4f9f4; /* Mint Background */
            --surface: #ffffff;
            --text-main: #1c2a1c;
            --text-muted: #6b7280;
            --border: #e5e7eb;
            --success: #16a34a;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background-color: var(--bg); color: var(--text-main); line-height: 1.6; }

        /* --- HEADER NAV (Consistent with Appointment UI) --- */
        .header-nav {
            background: var(--primary);
            padding: 1rem 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .nav-brand img { height: 40px; filter: brightness(0) invert(1); }
        .logo { color: white; font-weight: 800; font-size: 1.5rem; text-decoration: none; }
        .nav-links a { color: rgba(255,255,255,0.8); text-decoration: none; margin-left: 24px; font-size: 0.9rem; transition: 0.3s; }
        .nav-links a:hover, .nav-links a.active { color: white; font-weight: 600; }

        /* --- LAYOUT --- */
        .container { max-width: 1000px; margin: 40px auto; padding: 0 20px; }
        
        .profile-header {
            display: flex;
            align-items: center;
            gap: 25px;
            margin-bottom: 35px;
            background: white;
            padding: 30px;
            border-radius: 24px;
            box-shadow: 0 10px 25px rgba(26, 77, 46, 0.05);
        }
        .avatar-circle {
            width: 100px; height: 100px;
            background: var(--bg);
            border: 3px solid var(--secondary);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 2.5rem; color: var(--primary); font-weight: 700;
        }

        .card {
            background: var(--surface);
            border-radius: 24px;
            padding: 35px;
            box-shadow: 0 10px 25px rgba(26, 77, 46, 0.05);
            border: 1px solid rgba(0,0,0,0.02);
            margin-bottom: 30px;
        }

        .card-title { 
            font-size: 1.25rem; font-weight: 700; margin-bottom: 25px; 
            display: flex; align-items: center; gap: 10px; color: var(--primary);
            border-bottom: 2px solid var(--bg); padding-bottom: 15px;
        }

        /* --- FORM STYLING --- */
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; }
        @media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }

        .form-group { margin-bottom: 20px; }
        .form-group label { 
            display: block; font-size: 0.8rem; font-weight: 700; 
            margin-bottom: 8px; color: var(--text-muted); 
            text-transform: uppercase; letter-spacing: 0.5px;
        }

        .input-control {
            width: 100%;
            padding: 14px 16px;
            border-radius: 12px;
            border: 1.5px solid var(--border);
            background: #f9fafb;
            font-size: 0.95rem;
            transition: all 0.3s;
        }
        .input-control:focus { outline: none; border-color: var(--secondary); background: white; box-shadow: 0 0 0 4px rgba(79, 119, 45, 0.1); }
        .locked { background: #f3f4f6; color: #4b5563; cursor: not-allowed; font-weight: 600; }

        .btn-primary {
            background: var(--primary);
            color: white;
            padding: 16px 32px;
            border: none;
            border-radius: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: 0.3s;
            display: inline-flex; align-items: center; gap: 10px;
        }
        .btn-primary:hover { background: var(--secondary); transform: translateY(-2px); box-shadow: 0 5px 15px rgba(26, 77, 46, 0.2); }

        .alert {
            background: #dcfce7; color: #166534; padding: 16px; border-radius: 12px;
            margin-bottom: 30px; font-weight: 600; display: flex; align-items: center; gap: 10px;
        }

        .badge-verified {
            display: inline-flex; align-items: center; gap: 8px;
            background: #f0fdf4; color: #166534; padding: 8px 16px;
            border-radius: 100px; font-size: 0.75rem; font-weight: 700;
            border: 1px solid #bbf7d0; margin-top: 20px;
        }
    </style>
</head>
<body>

    <nav class="header-nav">
        <div class="nav-brand">
            <img src="images/Logo_only.png" alt="Health4Q">
        </div>        
        <div class="nav-links">
            <a href="patient-dashboard.php">Dashboard</a>
            <a href="patientprofile.php" class="active">My Profile</a>
            <a href="patientappoint.php">Appointments</a>
            <a href="patientmedhist.php">Medical History</a>
            <a href="logout.php" style="color: #ff9999;">Logout</a>
        </div>
    </nav>

    <div class="container">
        
        <div class="profile-header">
            <div class="avatar-circle">
                <?php echo strtoupper(substr($patient['first_name'], 0, 1)); ?>
            </div>
            <div>
                <h1 style="color: var(--primary);"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h1>
                <p style="color: var(--text-muted);">Patient ID: #P-<?php echo str_pad($patient['patient_id'], 5, '0', STR_PAD_LEFT); ?></p>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert">✓ Profile updated successfully! Your changes are now live.</div>
        <?php endif; ?>

        <div class="grid-2">
            <section class="card">
                <h3 class="card-title">📞 Contact Information</h3>
                <form action="" method="POST">
                    <input type="hidden" name="action" value="update_personal">
                    
                    <div class="form-group">
                        <label>Email Address (Verified)</label>
                        <input type="text" class="input-control locked" value="<?php echo htmlspecialchars($patient['email']); ?>" readonly>
                    </div>

                    <div class="form-group">
                        <label>Mobile Number</label>
                        <input type="text" name="phone" class="input-control" value="<?php echo htmlspecialchars($patient['phone'] ?? ''); ?>" placeholder="+63 000 000 0000">
                    </div>

                    <div class="form-group">
                        <label>Emergency Contact</label>
                        <input type="text" name="emergency_contact" class="input-control" value="<?php echo htmlspecialchars($patient['emergency_contact'] ?? ''); ?>" placeholder="Name & Phone">
                    </div>

                    <div class="form-group">
                        <label>Home Address</label>
                        <textarea name="address" class="input-control" rows="2" style="resize: none;"><?php echo htmlspecialchars($patient['address'] ?? ''); ?></textarea>
                    </div>

                    <button type="submit" class="btn-primary">Update Profile</button>
                </form>
            </section>

            <section class="card">
                <h3 class="card-title">🔒 Medical Baseline</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 25px;">
                    This clinical data is locked. To request changes, please visit our clinic with a valid ID.
                </p>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label>Blood Type</label>
                        <div class="input-control locked"><?php echo htmlspecialchars($patient['blood_type'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="form-group">
                        <label>Date of Birth</label>
                        <div class="input-control locked"><?php echo htmlspecialchars($patient['dob'] ?? 'N/A'); ?></div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Chronic Conditions</label>
                    <div class="input-control locked" style="min-height: 50px;">
                        <?php echo htmlspecialchars($patient['chronic_conditions'] ?? 'No records found'); ?>
                    </div>
                </div>

                <div class="form-group">
                    <label>Allergies</label>
                    <div class="input-control locked" style="min-height: 50px; border-left: 4px solid var(--secondary);">
                        <?php echo htmlspecialchars($patient['allergies'] ?? 'No reported allergies'); ?>
                    </div>
                </div>

                <div class="badge-verified">
                    🛡️ Verified by Health4Q Clinical Registry
                </div>
            </section>
        </div>
    </div>

</body>
</html>