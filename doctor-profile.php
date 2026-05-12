<?php
/**
 * doctor-profile.php
 * Enhanced UI version with Forest Green theme
 */
require_once 'config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Access Control
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'doctor') {
    header("Location: login.php");
    exit;
}

$pdo = getPDO();
$user_id = $_SESSION['user_id'];

// Handle profile update
$message = '';
$msg_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $specialty = htmlspecialchars($_POST['specialty'] ?? '');
    $clinic = htmlspecialchars($_POST['clinic'] ?? '');
    $notes = htmlspecialchars($_POST['notes'] ?? '');
    
    try {
        $stmt = $pdo->prepare('UPDATE doctor SET specialty = ?, clinic = ?, notes = ? WHERE user_id = ?');
        $stmt->execute([$specialty, $clinic, $notes, $user_id]);
        $message = 'Profile updated successfully!';
    } catch (Exception $e) {
        $message = 'Error updating profile: ' . $e->getMessage();
        $msg_type = 'error';
    }
}

// Fetch Doctor Profile (Updated to match your schema)
try {
    $stmt = $pdo->prepare('
        SELECT u.first_name, u.last_name, u.email, d.* FROM users u 
        JOIN doctor d ON u.user_id = d.user_id 
        WHERE u.user_id = ?
    ');
    $stmt->execute([$user_id]);
    $doctor = $stmt->fetch();
} catch (Exception $e) {
    die("Connection error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Profile | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-green: #1a4d34;
            --light-bg: #c5e6e1;
            --white: #ffffff;
            --accent-green: #2d6a4f;
            --text-dark: #1b4332;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Quicksand', sans-serif; }

        body {
            background: radial-gradient(circle at center, #d8f3dc 0%, var(--light-bg) 100%);
            min-height: 100vh;
            color: var(--text-dark);
        }

        /* --- NAVIGATION (Consistent with Dashboard) --- */
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
            margin-right: 10px;
        }
        .nav-links a.active { background: var(--accent-green); }
        .logout-btn { background: #d90429; color: white; padding: 8px 18px; border-radius: 5px; text-decoration: none; font-weight: bold; font-size: 12px; }

        /* --- PROFILE CONTENT --- */
        .container { max-width: 800px; margin: 40px auto; padding: 0 20px; }

        .profile-card {
            background: var(--white);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
        }

        .profile-header {
            background: var(--primary-green);
            padding: 40px;
            text-align: center;
            color: white;
        }

        .profile-img {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 5px solid rgba(255,255,255,0.2);
            margin-bottom: 15px;
            background: #eee;
        }

        .profile-body { padding: 40px; }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            font-size: 14px;
            font-weight: 600;
        }
        .alert-success { background: #dcfce7; color: #15803d; border-left: 5px solid #22c55e; }
        .alert-error { background: #fee2e2; color: #b91c1c; border-left: 5px solid #ef4444; }

        /* --- FORM STYLING --- */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group { margin-bottom: 20px; }
        .form-group.full { grid-column: span 2; }

        label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #888;
            text-transform: uppercase;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }

        input, textarea {
            width: 100%;
            padding: 12px 15px;
            border-radius: 10px;
            border: 2px solid #eee;
            background: #fcfcfc;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-dark);
            transition: 0.3s;
        }

        input:focus { border-color: var(--accent-green); outline: none; background: white; }
        input[readonly] { background: #f1f5f9; color: #64748b; cursor: not-allowed; }

        .save-btn {
            background: var(--primary-green);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 12px;
            font-weight: 800;
            cursor: pointer;
            width: 100%;
            margin-top: 10px;
            transition: 0.3s;
        }
        .save-btn:hover { background: var(--accent-green); transform: translateY(-2px); }

        .license-badge {
            background: #e0f2fe;
            color: #0369a1;
            padding: 4px 10px;
            border-radius: 5px;
            font-size: 11px;
            margin-top: 5px;
            display: inline-block;
        }
    </style>
</head>
<body>

    <nav class="top-nav">
        <div class="nav-brand">
            <img src="images/Logo_only.png" alt="Health4Q">
        </div>
        <div class="nav-links">
            <a href="doctor-dashboard.php">Dashboard</a>
            <a href="doctor-profile.php" class="active">Profile</a>
            <a href="doctor-appointment.php">Appointments</a>
            <a href="doctor-medical-data.php">Medical Data</a>
            <a href="issuance.php">Referrals</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </nav>

    <div class="container">
        <div class="profile-card">
            <div class="profile-header">
                <img src="assets/profile-placeholder.png" alt="Doctor" class="profile-img">
                <h2 style="font-weight: 800;">Dr. <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?></h2>
                <div class="license-badge">Verified License: <?php echo htmlspecialchars($doctor['license_no']); ?></div>
            </div>

            <div class="profile-body">
                <?php if (!empty($message)): ?>
                    <div class="alert alert-<?php echo $msg_type; ?>">
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Full Name</label>
                            <input type="text" value="<?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label>Account Email</label>
                            <input type="text" value="<?php echo htmlspecialchars($doctor['email']); ?>" readonly>
                        </div>

                        <div class="form-group">
                            <label>Specialization</label>
                            <input type="text" name="specialty" value="<?php echo htmlspecialchars($doctor['specialty']); ?>" required placeholder="e.g. Cardiologist">
                        </div>

                        <div class="form-group">
                            <label>Clinic / Hospital Name</label>
                            <input type="text" name="clinic" value="<?php echo htmlspecialchars($doctor['clinic']); ?>" placeholder="e.g. City Medical Center">
                        </div>

                        <div class="form-group full">
                            <label>Professional Bio / Notes</label>
                            <textarea name="notes" rows="4" placeholder="Briefly describe your services..."><?php echo htmlspecialchars($doctor['notes']); ?></textarea>
                        </div>
                    </div>

                    <button type="submit" class="save-btn">Update Profile Information</button>
                </form>
            </div>
        </div>
        
        <p style="text-align: center; margin-top: 20px; color: #888; font-size: 12px;">
            To change your email or license number, please contact administrative support.
        </p>
    </div>

</body>
</html>