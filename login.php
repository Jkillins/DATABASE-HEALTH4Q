<?php
/**
 * login.php 
 */
session_start();
require_once 'config.php';

// Generate CSRF Token for security
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Redirect if already logged in
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    $redirects = [
        'doctor' => 'doctor-dashboard.php',
        'clinical_assistant' => 'assistant-dashboard.php',
        'medical_assistant' => 'assistant-dashboard.php',
        'patient' => 'patient-dashboard.php'
    ];
    $target = $redirects[$_SESSION['role']] ?? 'patient-dashboard.php';
    header("Location: $target");
    exit;
}

$error = "";
$success = "";

if (isset($_GET['success'])) {
    $success = "Account created successfully! Please log in.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // Validate CSRF Token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Security validation failed. Please refresh and try again.";
    } else {
        if ($_POST['action'] === 'login') {
            try {
                $pdo = getPDO();
                $email = filter_var(trim($_POST['email']), FILTER_SANITIZE_EMAIL);
                $password = trim($_POST['password']);

                if (empty($email) || empty($password)) {
                    throw new Exception("Please enter both email and password.");
                }

                $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($password, $user['password'])) {
                    session_regenerate_id(true);
                    
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['email']   = $user['email'];
                    $_SESSION['name']    = $user['first_name'] . " " . $user['last_name'];
                    $_SESSION['role']    = $user['role'];

                    // Resolve and store the role_id in the session
                    $role_id = null;
                    if ($user['role'] === 'doctor') {
                        $stmtRole = $pdo->prepare("SELECT doctor_id FROM doctor WHERE user_id = ?");
                        $stmtRole->execute([$user['user_id']]);
                        $role_id = $stmtRole->fetchColumn();
                    } elseif (in_array($user['role'], ['clinical_assistant', 'medical_assistant'])) {
                        $stmtRole = $pdo->prepare("SELECT assistant_id FROM clinical_assistant WHERE user_id = ?");
                        $stmtRole->execute([$user['user_id']]);
                        $role_id = $stmtRole->fetchColumn();
                    } elseif ($user['role'] === 'patient') {
                        $stmtRole = $pdo->prepare("SELECT patient_id FROM patient WHERE user_id = ?");
                        $stmtRole->execute([$user['user_id']]);
                        $role_id = $stmtRole->fetchColumn();
                    }
                    $_SESSION['role_id'] = $role_id ? (int)$role_id : null;

                    // Optional: Handle "Remember Me" logic
                    if (isset($_POST['remember_me'])) {
                        setcookie("remember_email", $email, time() + (30 * 24 * 60 * 60), "/");
                    } else {
                        setcookie("remember_email", "", time() - 3600, "/");
                    }

                    $target = (in_array($_SESSION['role'], ['clinical_assistant', 'medical_assistant'])) 
                              ? "assistant-dashboard.php" 
                              : ($_SESSION['role'] === "doctor" ? "doctor-dashboard.php" : "patient-dashboard.php");
                    
                    header("Location: $target");
                    exit;
                } else {
                    throw new Exception("Invalid email or password.");
                }
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #0288B4;
            --dark-color: #1a1a1a;
            --bg-overlay: rgba(0, 0, 0, 0.4);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: url('images/background_login.png') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 8%;
        }

        .form-container { 
            width: 100%;
            max-width: 450px;
            background: rgba(255, 255, 255, 0.98);
            border-radius: 24px; 
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.25);
            animation: slideIn 0.5s ease-out;
            position: relative;
        }

        @keyframes slideIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }

        .back-link {
            display: inline-flex;
            align-items: center;
            color: #777;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 20px;
            transition: 0.2s;
        }
        .back-link:hover { color: var(--primary-color); }
        .back-link i { margin-right: 8px; }

        .form-header { text-align: center; margin-bottom: 30px; }
        .form-header img { height: 55px; margin-bottom: 15px; }
        .form-header h2 { font-size: 24px; color: var(--dark-color); font-weight: 700; }
        .form-header p { color: #888; font-size: 14px; }

        .form-group { margin-bottom: 20px; position: relative; }
        label { display: block; font-size: 13px; font-weight: 600; color: #444; margin-bottom: 8px; }
        
        input[type="email"], input[type="password"], input[type="text"] { 
            width: 100%; padding: 14px 16px; border: 1.5px solid #eee; 
            border-radius: 12px; font-size: 14px; background: #f9f9f9; transition: all 0.3s;
        }
        input:focus { border-color: var(--primary-color); outline: none; background: #fff; box-shadow: 0 0 0 4px rgba(2,136,180,0.1); }

        .pass-wrapper { position: relative; }
        .toggle-password {
            position: absolute; right: 15px; top: 50%; transform: translateY(-50%);
            cursor: pointer; color: #aaa; transition: 0.2s;
        }
        .toggle-password:hover { color: var(--primary-color); }

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            color: #666;
            cursor: pointer;
            user-select: none;
        }
        .remember-me input { width: 16px; height: 16px; accent-color: var(--primary-color); }

        .forgot-link a { font-size: 13px; color: var(--primary-color); text-decoration: none; font-weight: 600; cursor: pointer; }

        .submit-btn {
            width: 100%; padding: 16px; background: var(--dark-color); color: white; border: none;
            border-radius: 12px; font-weight: 600; cursor: pointer; font-size: 16px; transition: 0.3s;
        }
        .submit-btn:hover { background: #333; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.2); }

        .alert { padding: 14px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .error { background: #fff1f2; color: #be123c; border: 1px solid #fecdd3; }
        .success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }

        .footer-link { text-align: center; margin-top: 30px; font-size: 14px; color: #666; }
        .footer-link a { color: var(--primary-color); text-decoration: none; font-weight: 700; }

        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: var(--bg-overlay); backdrop-filter: blur(8px);
            display: none; align-items: center; justify-content: center; z-index: 1000;
        }
        .modal-card {
            background: white; padding: 40px; border-radius: 24px; width: 90%; max-width: 400px;
            text-align: center; box-shadow: 0 25px 50px rgba(0,0,0,0.3);
        }
    </style>
</head>
<body>

    <!-- Forgot Password Modal -->
    <div class="modal-overlay" id="fpModal">
        <div class="modal-card">
            <h3>Reset Password</h3>
            <p style="font-size: 14px; color: #666; margin: 15px 0 25px;">Provide your email and we'll send instructions.</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="forgot_password">
                <div class="form-group" style="text-align: left;">
                    <label>Email Address</label>
                    <input type="email" name="fp_email" placeholder="email@example.com" required>
                </div>
                <button type="submit" class="submit-btn">Reset Password</button>
                <button type="button" class="submit-btn" style="background:#eee; color:#666; margin-top:10px;" onclick="toggleModal(false)">Cancel</button>
            </form>
        </div>
    </div>

    <div class="form-container">
        <!-- Back Link -->
        <a href="index.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Home
        </a>

        <div class="form-header">
            <img src="images/Logo_name.png" alt="Health4Q">
            <h2>Welcome Back</h2>
            <p>Access your health dashboard</p>
        </div>

        <!-- Feedback Alerts -->
        <?php if ($error): ?> 
            <div class="alert error"><i class="fas fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div> 
        <?php endif; ?>
        
        <?php if ($success): ?> 
            <div class="alert success"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div> 
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <input type="hidden" name="action" value="login">

            <!-- Email -->
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" value="<?php echo isset($_COOKIE['remember_email']) ? htmlspecialchars($_COOKIE['remember_email']) : ''; ?>" placeholder="name@example.com" required>
            </div>

            <!-- Password -->
            <div class="form-group">
                <label>Password</label>
                <div class="pass-wrapper">
                    <input type="password" name="password" id="loginPass" placeholder="••••••••" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePass()"></i>
                </div>
            </div>

            <!-- Remember Me & Forgot Password -->
            <div class="form-options">
                <label class="remember-me">
                    <input type="checkbox" name="remember_me" <?php echo isset($_COOKIE['remember_email']) ? 'checked' : ''; ?>> Remember me
                </label>
                <div class="forgot-link">
                    <a onclick="toggleModal(true)">Forgot Password?</a>
                </div>
            </div>

            <button type="submit" class="submit-btn">Sign In</button>

            <div class="footer-link">
                Don't have an account? <a href="register.php">Sign Up</a>
            </div>
        </form>
    </div>

    <script>
        function toggleModal(show) {
            document.getElementById('fpModal').style.display = show ? 'flex' : 'none';
        }

        function togglePass() {
            const passInput = document.getElementById('loginPass');
            const icon = document.querySelector('.toggle-password');
            if (passInput.type === 'password') {
                passInput.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                passInput.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        window.onclick = function(event) {
            const modal = document.getElementById('fpModal');
            if (event.target == modal) toggleModal(false);
        }
    </script>
</body>
</html>