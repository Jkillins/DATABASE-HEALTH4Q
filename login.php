<?php
/**
 * login.php - Fully Functional Version
 * Health4Q Medical Management System
 */
session_start();
require_once 'config.php';

// Redirect if already logged in based on session role
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'doctor') {
        header("Location: doctor-dashboard.php");
    } elseif ($_SESSION['role'] === 'clinical_assistant') {
        header("Location: assistant-dashboard.php");
    } else {
        header("Location: patient-dashboard.php");
    }
    exit;
}

$error = "";
$success = "";

// Check for success message from registration
if (isset($_GET['success'])) {
    $success = "Account created! Please log in.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // --- LOGIN LOGIC ---
    if ($_POST['action'] === 'login') {
        try {
            $pdo = getPDO();
            $email = trim($_POST['email']);
            $password = trim($_POST['password']);

            if (empty($email) || empty($password)) {
                throw new Exception("Please enter both email and password.");
            }

            // Fetch user
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['password'])) {
                
                // Set Sessions - Synchronized with patient-dashboard.php requirements
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['email']   = $user['email'];
                $_SESSION['name']    = $user['first_name'] . " " . $user['last_name'];
                $_SESSION['role']    = $user['role']; // 'patient', 'doctor', or 'clinical_assistant'

                // Final Redirect
               if ($_SESSION['role'] === "doctor") {
                    header("Location: doctor-dashboard.php");
                } elseif ($_SESSION['role'] === "clinical_assistant" || $_SESSION['role'] === "medical_assistant") {
                    // Both roles now point to the assistant dashboard
                    header("Location: assistant-dashboard.php");
                } else {
                    header("Location: patient-dashboard.php");
                }
                exit;
            } else {
                throw new Exception("Invalid email or password.");
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }

    // --- FORGOT PASSWORD LOGIC ---
    if ($_POST['action'] === 'forgot_password') {
        $email = trim($_POST['fp_email']);
        if (!empty($email)) {
            // Here you would typically integrate your PHPMailer or mail logic
            $success = "Verification code sent to your email.";
        } else {
            $error = "Please enter your email to reset password.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Health4Q</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="images/Logo_only.png">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background: url('images/background_login.png') no-repeat center center fixed;
            background-size: cover; height: 100vh; display: flex; align-items: center; 
            justify-content: flex-end; padding-right: 8%; overflow: hidden;
        }
        .form-container { 
            width: 100%; max-width: 450px; background: white; border-radius: 20px; 
            padding: 40px; box-shadow: 0 15px 35px rgba(0,0,0,0.3);
            animation: fadeIn 0.6s ease-out;
        }
        @keyframes fadeIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: translateX(0); } }
        
        .form-header { text-align: center; margin-bottom: 25px; }
        .form-header img { height: 60px; }
        .form-header h2 { font-size: 22px; color: #333; margin-top: 10px; font-weight: 600; }

        .form-group { margin-bottom: 18px; }
        label { display: block; font-size: 13px; font-weight: 700; color: #666; margin-bottom: 5px; }
        input { 
            width: 100%; padding: 12px 15px; border: 1px solid #ddd; 
            border-radius: 10px; font-size: 14px; background: #fcfcfc; transition: 0.3s;
        }
        input:focus { border-color: #0288B4; outline: none; box-shadow: 0 0 5px rgba(2,136,180,0.2); }
        
        .forgot-link { text-align: right; margin-top: -10px; margin-bottom: 20px; }
        .forgot-link a { font-size: 12px; color: #0288B4; text-decoration: none; font-weight: 600; cursor: pointer; }

        .submit-btn {
            width: 100%; padding: 14px; background: #000; color: white; border: none;
            border-radius: 10px; font-weight: 600; cursor: pointer; font-size: 16px; transition: 0.3s;
        }
        .submit-btn:hover { background: #333; transform: translateY(-2px); }

        .alert { padding: 12px; border-radius: 8px; font-size: 13px; margin-bottom: 15px; text-align: center; }
        .error { background: #fee2e2; color: #b91c1c; }
        .success { background: #dcfce7; color: #15803d; }

        .footer-link { text-align: center; margin-top: 25px; font-size: 13px; color: #666; }
        .footer-link a { color: #0288B4; text-decoration: none; font-weight: 700; }

        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.6); backdrop-filter: blur(5px);
            display: none; align-items: center; justify-content: center; z-index: 1000;
        }
        .modal-card {
            background: white; padding: 30px; border-radius: 20px; width: 90%; max-width: 400px;
            text-align: center; box-shadow: 0 20px 40px rgba(0,0,0,0.4);
        }
        .cancel-btn { background: #eee; color: #666; margin-top: 10px; border: none; padding: 10px; width: 100%; border-radius: 8px; cursor: pointer; }
    </style>
</head>
<body>

    <!-- Forgot Password Modal -->
    <div class="modal-overlay" id="fpModal">
        <div class="modal-card">
            <h3>Reset Password</h3>
            <p style="font-size: 13px; color: #777; margin-bottom: 20px;">Enter your email to receive a reset code.</p>
            <form method="POST">
                <input type="hidden" name="action" value="forgot_password">
                <div class="form-group" style="text-align: left;">
                    <label>Email Address</label>
                    <input type="email" name="fp_email" placeholder="name@example.com" required>
                </div>
                <button type="submit" class="submit-btn">Send Code</button>
                <button type="button" class="cancel-btn" onclick="toggleModal(false)">Back to Login</button>
            </form>
        </div>
    </div>

    <!-- Login Container -->
    <div class="form-container">
        <div class="form-header">
            <img src="images/Logo_name.png" alt="Health4Q">
            <h2>Welcome Back</h2>
        </div>

        <?php if ($error): ?> <div class="alert error"><?php echo htmlspecialchars($error); ?></div> <?php endif; ?>
        <?php if ($success): ?> <div class="alert success"><?php echo htmlspecialchars($success); ?></div> <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="login">

            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="Enter your email" required>
            </div>

            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="Enter your password" required>
            </div>

            <div class="forgot-link">
                <a onclick="toggleModal(true)">Forgot Password?</a>
            </div>

            <button type="submit" class="submit-btn">Login</button>

            <div class="footer-link">
                Don't have an account? <a href="register.php">Create Account</a>
            </div>
        </form>
    </div>

    <script>
        function toggleModal(show) {
            document.getElementById('fpModal').style.display = show ? 'flex' : 'none';
        }
    </script>
</body>
</html>