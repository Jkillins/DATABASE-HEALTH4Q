<?php
/**
 * register.php - Comprehensive Registration with Success Popup
 * Health4Q Medical Management System
 * Updated: Removed blood_type
 */
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$show_success = false;

if (isset($_SESSION['registration_success'])) {
    $show_success = true;
    unset($_SESSION['registration_success']); 
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    try {
        $pdo = getPDO();
        
        $email = sanitize($_POST['email'] ?? '');
        $role = sanitize($_POST['role'] ?? '');
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';

        if (empty($email) || empty($password) || empty($role)) {
            throw new Exception('Email, Password, and Role are required.');
        }
        if ($password !== $password_confirm) {
            throw new Exception('Passwords do not match.');
        }

        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) { throw new Exception('Email already registered.'); }

        $pdo->beginTransaction();

        // 1. Insert into 'users'
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (email, password, first_name, last_name, contact_no, role, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->execute([
            $email, 
            $password_hash, 
            sanitize($_POST['first_name'] ?? ''), 
            sanitize($_POST['last_name'] ?? ''), 
            sanitize($_POST['contact_no'] ?? ''), 
            $role
        ]);
        $user_id = $pdo->lastInsertId();

        // 2. Insert into 'address'
        $stmt = $pdo->prepare("INSERT INTO address (user_id, zipcode, barangay, city, province) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $user_id, 
            sanitize($_POST['zipcode'] ?? ''), 
            sanitize($_POST['barangay'] ?? ''), 
            sanitize($_POST['city'] ?? ''), 
            sanitize($_POST['province'] ?? '')
        ]);

        // 3. Role-Specific Tables (Removed blood_type here)
        if ($role === 'patient') {
            $stmt = $pdo->prepare("INSERT INTO patient (user_id, date_of_birth, sex) VALUES (?, ?, ?)");
            $stmt->execute([$user_id, $_POST['dob'] ?: null, $_POST['sex'] ?? 'other']);
        } elseif ($role === 'doctor') {
            $stmt = $pdo->prepare("INSERT INTO doctor (user_id, license_no, specialty, clinic) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, sanitize($_POST['license_no'] ?? 'TBD'), sanitize($_POST['specialty'] ?? 'General'), sanitize($_POST['clinic'] ?? '')]);
        } elseif ($role === 'clinical_assistant') {
            $stmt = $pdo->prepare("INSERT INTO clinical_assistant (user_id, clinic) VALUES (?, ?)");
            $stmt->execute([$user_id, sanitize($_POST['clinic'] ?? '')]);
        }

        $pdo->commit();
        
        $_SESSION['registration_success'] = true;
        header("Location: register.php"); 
        exit;

    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        /* CSS styles remain exactly as your original UI to avoid breaking layout */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background: url('images/background_login.png') no-repeat center center fixed;
            background-size: cover; height: 100vh; display: flex; align-items: center; 
            justify-content: flex-end; padding-right: 6%; overflow: hidden;
        }
        .form-container { 
            width: 100%; max-width: 620px; background: white; border-radius: 20px; 
            padding: 25px 35px; box-shadow: 0 15px 35px rgba(0,0,0,0.3);
        }
        .form-header { text-align: center; margin-bottom: 15px; }
        .form-header img { height: 45px; }
        .form-header h2 { font-size: 20px; color: #333; margin-top: 5px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 20px; }
        .full-width { grid-column: span 2; }
        .form-group { margin-bottom: 5px; }
        label { display: block; font-size: 11px; font-weight: 700; color: #666; margin-bottom: 2px; }
        input, select { 
            width: 100%; padding: 8px 12px; border: 1px solid #ddd; 
            border-radius: 8px; font-size: 13px; background: #fcfcfc;
        }
        .section-title { 
            grid-column: span 2; font-size: 12px; font-weight: 800; 
            color: #0288B4; margin-top: 10px; border-bottom: 1px solid #eee; 
        }
        .role-section {
            grid-column: span 2; display: none; grid-template-columns: 1fr 1fr; gap: 8px 20px;
            background: #f8fafc; padding: 10px; border-radius: 10px; border: 1px solid #e2e8f0;
        }
        .submit-btn {
            width: 100%; padding: 12px; background: #000; color: white; border: none;
            border-radius: 8px; font-weight: 600; cursor: pointer; margin-top: 15px; transition: 0.3s;
        }
        .submit-btn:hover { background: #333; }
        .confirmation-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.7); backdrop-filter: blur(8px);
            display: <?php echo $show_success ? 'flex' : 'none'; ?>;
            align-items: center; justify-content: center; z-index: 9999;
        }
        .confirmation-card {
            background: white; padding: 40px; border-radius: 25px; text-align: center;
            width: 90%; max-width: 420px; box-shadow: 0 25px 50px rgba(0,0,0,0.5);
            position: relative; animation: popIn 0.5s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }
        @keyframes popIn { from { transform: scale(0.8); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .confirmation-title { color: #0288B4; font-size: 26px; font-weight: 700; margin-top: 20px; }
        .confirmation-message { color: #666; margin: 10px 0 30px; font-size: 15px; }
        .btn-confirm {
            background: #000; color: white; padding: 14px 40px; border: none;
            border-radius: 10px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block;
        }
        .back-btn-popup { position: absolute; top: 20px; left: 20px; background: none; border: none; font-size: 22px; cursor: pointer; color: #999; }
        .alert { background: #fee2e2; color: #b91c1c; padding: 8px; border-radius: 6px; font-size: 12px; margin-bottom: 10px; text-align: center; }
        .login-link { text-align: center; margin-top: 12px; font-size: 12px; }
        .login-link a { color: #0288B4; text-decoration: none; font-weight: 700; }
    </style>
</head>
<body>

    <div class="confirmation-overlay" id="successPopup">
        <div class="confirmation-card">
            <button class="back-btn-popup" onclick="document.getElementById('successPopup').style.display='none'">←</button>
            <img src="images/Logo_name.png" alt="Health4Q" width="200">
            <h2 class="confirmation-title">Congratulations!!!</h2>
            <p class="confirmation-message">Account Successfully Created</p>
            <a href="login.php" class="btn-confirm">Go to Login</a>
        </div>
    </div>

    <div class="form-container">
        <div class="form-header">
            <img src="images/Logo_name.png" alt="Health4Q">
            <h2>Create Account</h2>
        </div>

        <?php if ($error): ?>
            <div class="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="register">

            <div class="form-grid">
                <div class="form-group full-width">
                    <label>I am a... *</label>
                    <select name="role" id="roleSelect" onchange="updateRoleUI()" required>
                        <option value="">-- Choose Role --</option>
                        <option value="patient">Patient</option>
                        <option value="doctor">Doctor</option>
                        <option value="clinical_assistant">Clinical Assistant</option>
                    </select>
                </div>

                <div class="form-group"><label>First Name *</label><input type="text" name="first_name" required></div>
                <div class="form-group"><label>Last Name *</label><input type="text" name="last_name" required></div>
                <div class="form-group"><label>Email *</label><input type="email" name="email" required></div>
                <div class="form-group"><label>Contact Number</label><input type="tel" name="contact_no"></div>
                <div class="form-group"><label>Password *</label><input type="password" name="password" required></div>
                <div class="form-group"><label>Confirm Password *</label><input type="password" name="password_confirm" required></div>

                <div class="section-title">Address & Role Details</div>

                <div class="form-group"><label>Barangay</label><input type="text" name="barangay"></div>
                <div class="form-group"><label>City</label><input type="text" name="city"></div>
                <div class="form-group"><label>Province</label><input type="text" name="province"></div>
                <div class="form-group"><label>Zipcode</label><input type="text" name="zipcode"></div>

                <div id="section-patient" class="role-section">
                    <div class="form-group"><label>Birthday</label><input type="date" name="dob"></div>
                    <div class="form-group"><label>Sex</label><select name="sex"><option value="male">Male</option><option value="female">Female</option></select></div>
                </div>

                <div id="section-doctor" class="role-section">
                    <div class="form-group"><label>License Number *</label><input type="text" name="license_no"></div>
                    <div class="form-group"><label>Specialty</label><input type="text" name="specialty"></div>
                </div>
            </div>

            <button type="submit" class="submit-btn">Create Account</button>

            <div class="login-link">
                Already have an account? <a href="login.php">Sign In</a>
            </div>
        </form>
    </div>

    <script>
        function updateRoleUI() {
            const role = document.getElementById('roleSelect').value;
            const pSec = document.getElementById('section-patient');
            const dSec = document.getElementById('section-doctor');
            
            pSec.style.display = (role === 'patient') ? 'grid' : 'none';
            dSec.style.display = (role === 'doctor') ? 'grid' : 'none';
        }
    </script>
</body>
</html>