<?php
/**
 * register.php - STRICT VALIDATION VERSION
 * Health4Q Medical Management System
 */

require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

        // =========================
        // SANITIZE INPUTS
        // =========================
        $email = trim(sanitize($_POST['email'] ?? ''));
        $role = trim(sanitize($_POST['role'] ?? ''));
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';

        $first_name = trim(sanitize($_POST['first_name'] ?? ''));
        $last_name = trim(sanitize($_POST['last_name'] ?? ''));
        $contact_no = trim(sanitize($_POST['contact_no'] ?? ''));
        $barangay = trim(sanitize($_POST['barangay'] ?? ''));
        $city = trim(sanitize($_POST['city'] ?? ''));
        $province = trim(sanitize($_POST['province'] ?? ''));
        $zipcode = trim(sanitize($_POST['zipcode'] ?? ''));

        // =========================
        // REQUIRED VALIDATION
        // =========================
        if (empty($role)) {
            throw new Exception('Please select a role.');
        }

        if (empty($first_name)) {
            throw new Exception('First name is required.');
        }

        if (empty($last_name)) {
            throw new Exception('Last name is required.');
        }

        if (empty($email)) {
            throw new Exception('Email is required.');
        }

        if (empty($password)) {
            throw new Exception('Password is required.');
        }

        if (empty($password_confirm)) {
            throw new Exception('Confirm password is required.');
        }

        // =========================
        // NAME VALIDATION
        // =========================
        if (!preg_match("/^[a-zA-Z\s]+$/", $first_name)) {
            throw new Exception('First name must contain letters only.');
        }

        if (!preg_match("/^[a-zA-Z\s]+$/", $last_name)) {
            throw new Exception('Last name must contain letters only.');
        }

        // =========================
        // EMAIL VALIDATION
        // =========================
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format.');
        }

        // =========================
        // CONTACT NUMBER VALIDATION
        // =========================
        if (!empty($contact_no)) {

            if (!preg_match('/^(09|\+639)\d{9}$/', $contact_no)) {
                throw new Exception('Invalid Philippine contact number.');
            }
        }

        // =========================
        // PASSWORD VALIDATION
        // =========================
        if (strlen($password) < 8) {
            throw new Exception('Password must be at least 8 characters.');
        }

        if (!preg_match('/[A-Z]/', $password)) {
            throw new Exception('Password must contain at least one uppercase letter.');
        }

        if (!preg_match('/[a-z]/', $password)) {
            throw new Exception('Password must contain at least one lowercase letter.');
        }

        if (!preg_match('/[0-9]/', $password)) {
            throw new Exception('Password must contain at least one number.');
        }

        if (!preg_match('/[\W]/', $password)) {
            throw new Exception('Password must contain at least one special character.');
        }

        // =========================
        // PASSWORD MATCH
        // =========================
        if ($password !== $password_confirm) {
            throw new Exception('Passwords do not match.');
        }

        // =========================
        // ZIPCODE VALIDATION
        // =========================
        if (!empty($zipcode) && !preg_match('/^[0-9]{4}$/', $zipcode)) {
            throw new Exception('Zipcode must be exactly 4 digits.');
        }

        // =========================
        // ROLE VALIDATION
        // =========================
        $allowed_roles = ['patient', 'doctor', 'clinical_assistant'];

        if (!in_array($role, $allowed_roles)) {
            throw new Exception('Invalid role selected.');
        }

        // =========================
        // PATIENT VALIDATION
        // =========================
        if ($role === 'patient') {

            $dob = $_POST['dob'] ?? '';
            $sex = $_POST['sex'] ?? '';

            if (empty($dob)) {
                throw new Exception('Birthday is required for patients.');
            }

            $birthDate = new DateTime($dob);
            $today = new DateTime();

            if ($birthDate > $today) {
                throw new Exception('Birthday cannot be in the future.');
            }

            if (!in_array($sex, ['male', 'female'])) {
                throw new Exception('Invalid sex selected.');
            }
        }

        // =========================
        // DOCTOR VALIDATION
        // =========================
        if ($role === 'doctor') {

            $license_no = trim(sanitize($_POST['license_no'] ?? ''));
            $specialty = trim(sanitize($_POST['specialty'] ?? ''));

            if (empty($license_no)) {
                throw new Exception('License number is required.');
            }

            if (strlen($license_no) < 5) {
                throw new Exception('License number is too short.');
            }

            if (empty($specialty)) {
                throw new Exception('Specialty is required.');
            }
        }

        // =========================
        // CHECK DUPLICATE EMAIL
        // =========================
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            throw new Exception('Email already registered.');
        }

        // =========================
        // START TRANSACTION
        // =========================
        $pdo->beginTransaction();

        // USERS TABLE
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO users 
            (email, password, first_name, last_name, contact_no, role, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $email,
            $password_hash,
            $first_name,
            $last_name,
            $contact_no,
            $role
        ]);

        $user_id = $pdo->lastInsertId();

        // ADDRESS TABLE
        $stmt = $pdo->prepare("
            INSERT INTO address
            (user_id, zipcode, barangay, city, province)
            VALUES (?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $user_id,
            $zipcode,
            $barangay,
            $city,
            $province
        ]);

        // PATIENT TABLE
        if ($role === 'patient') {

            $stmt = $pdo->prepare("
                INSERT INTO patient
                (user_id, date_of_birth, sex)
                VALUES (?, ?, ?)
            ");

            $stmt->execute([
                $user_id,
                $dob,
                $sex
            ]);
        }

        // DOCTOR TABLE
        elseif ($role === 'doctor') {

            $stmt = $pdo->prepare("
                INSERT INTO doctor
                (user_id, license_no, specialty, clinic)
                VALUES (?, ?, ?, ?)
            ");

            $stmt->execute([
                $user_id,
                $license_no,
                $specialty,
                sanitize($_POST['clinic'] ?? '')
            ]);
        }

        // CLINICAL ASSISTANT TABLE
        elseif ($role === 'clinical_assistant') {

            $stmt = $pdo->prepare("
                INSERT INTO clinical_assistant
                (user_id, clinic)
                VALUES (?, ?)
            ");

            $stmt->execute([
                $user_id,
                sanitize($_POST['clinic'] ?? '')
            ]);
        }

        $pdo->commit();

        $_SESSION['registration_success'] = true;

        header("Location: register.php");
        exit;

    } catch (Exception $e) {

        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

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

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
}

body{
    font-family:'Poppins',sans-serif;
    background:url('images/background_login.png') no-repeat center center fixed;
    background-size:cover;
    height:100vh;
    display:flex;
    align-items:center;
    justify-content:flex-end;
    padding-right:6%;
    overflow:hidden;
}

.form-container{
    width:100%;
    max-width:650px;
    background:white;
    border-radius:20px;
    padding:25px 35px;
    box-shadow:0 15px 35px rgba(0,0,0,0.3);
}

.back-btn-container{
    margin-bottom:15px;
}

.btn-back-home{
    text-decoration:none;
    color:#666;
    font-size:13px;
    font-weight:600;
}

.btn-back-home:hover{
    color:#0288B4;
}

.form-header{
    text-align:center;
    margin-bottom:15px;
}

.form-header img{
    height:45px;
}

.form-header h2{
    margin-top:5px;
    font-size:22px;
}

.form-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px 20px;
}

.full-width{
    grid-column:span 2;
}

.form-group{
    margin-bottom:5px;
}

label{
    display:block;
    margin-bottom:3px;
    font-size:11px;
    font-weight:700;
    color:#666;
}

input,
select{
    width:100%;
    padding:10px;
    border:1px solid #ddd;
    border-radius:8px;
    font-size:13px;
    background:#fcfcfc;
}

input:focus,
select:focus{
    outline:none;
    border-color:#0288B4;
}

.section-title{
    grid-column:span 2;
    font-size:12px;
    font-weight:800;
    color:#0288B4;
    margin-top:10px;
    border-bottom:1px solid #eee;
    padding-bottom:5px;
}

.role-section{
    grid-column:span 2;
    display:none;
    grid-template-columns:1fr 1fr;
    gap:10px 20px;
    background:#f8fafc;
    padding:10px;
    border-radius:10px;
    border:1px solid #e2e8f0;
}

.submit-btn{
    width:100%;
    padding:12px;
    background:#000;
    color:white;
    border:none;
    border-radius:8px;
    font-weight:600;
    cursor:pointer;
    margin-top:15px;
    transition:0.3s;
}

.submit-btn:hover{
    background:#333;
}

.alert{
    background:#fee2e2;
    color:#b91c1c;
    padding:10px;
    border-radius:8px;
    margin-bottom:15px;
    text-align:center;
    font-size:13px;
}

.login-link{
    text-align:center;
    margin-top:12px;
    font-size:12px;
}

.login-link a{
    color:#0288B4;
    text-decoration:none;
    font-weight:700;
}

/* SUCCESS POPUP */

.confirmation-overlay{
    position:fixed;
    top:0;
    left:0;
    width:100%;
    height:100%;
    background:rgba(0,0,0,0.7);
    backdrop-filter:blur(8px);

    display:<?php echo $show_success ? 'flex' : 'none'; ?>;

    align-items:center;
    justify-content:center;
    z-index:9999;
}

.confirmation-card{
    background:white;
    padding:40px;
    border-radius:25px;
    text-align:center;
    width:90%;
    max-width:420px;
    box-shadow:0 25px 50px rgba(0,0,0,0.5);
}

.confirmation-title{
    color:#0288B4;
    font-size:26px;
    font-weight:700;
    margin-top:20px;
}

.confirmation-message{
    color:#666;
    margin:10px 0 30px;
}

.btn-confirm{
    background:#000;
    color:white;
    padding:14px 40px;
    border:none;
    border-radius:10px;
    text-decoration:none;
    font-weight:600;
}

@media(max-width:768px){

    body{
        justify-content:center;
        padding:20px;
        height:auto;
    }

    .form-grid{
        grid-template-columns:1fr;
    }

    .full-width,
    .section-title,
    .role-section{
        grid-column:span 1;
    }
}

</style>
</head>

<body>

<!-- SUCCESS POPUP -->
<div class="confirmation-overlay" id="successPopup">

    <div class="confirmation-card">

        <img src="images/Logo_name.png" alt="Health4Q" width="100">

        <h2 class="confirmation-title">
            Congratulations!
        </h2>

        <p class="confirmation-message">
            Account Successfully Created
        </p>

        <a href="login.php" class="btn-confirm">
            Go to Login
        </a>

    </div>

</div>

<!-- FORM -->
<div class="form-container">

    <div class="back-btn-container">
        <a href="index.php" class="btn-back-home">
            ← Back to Home
        </a>
    </div>

    <div class="form-header">
        <img src="images/Logo_name.png" alt="Health4Q">
        <h2>Create Account</h2>
    </div>

    <?php if($error): ?>
        <div class="alert">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST">

        <input type="hidden" name="action" value="register">

        <div class="form-grid">

            <!-- ROLE -->
            <div class="form-group full-width">
                <label>I am a... *</label>

                <select name="role" id="roleSelect" onchange="updateRoleUI()" required>

                    <option value="">-- Choose Role --</option>

                    <option value="patient">Patient</option>

                    <option value="doctor">Doctor</option>

                    <option value="clinical_assistant">Clinical Assistant</option>

                </select>
            </div>

            <!-- BASIC INFO -->
            <div class="form-group">
                <label>First Name *</label>

                <input
                    type="text"
                    name="first_name"
                    required
                    pattern="[A-Za-z\s]+"
                    title="Letters only">
            </div>

            <div class="form-group">
                <label>Last Name *</label>

                <input
                    type="text"
                    name="last_name"
                    required
                    pattern="[A-Za-z\s]+"
                    title="Letters only">
            </div>

            <div class="form-group">
                <label>Email *</label>

                <input
                    type="email"
                    name="email"
                    required>
            </div>

            <div class="form-group">
                <label>Contact Number</label>

                <input
                    type="tel"
                    name="contact_no"
                    pattern="^(09|\+639)\d{9}$"
                    title="Enter valid Philippine mobile number">
            </div>

            <!-- PASSWORD -->
            <div class="form-group">
                <label>Password *</label>

                <input
                    type="password"
                    name="password"
                    required
                    minlength="8">
            </div>

            <div class="form-group">
                <label>Confirm Password *</label>

                <input
                    type="password"
                    name="password_confirm"
                    required>
            </div>

            <!-- ADDRESS -->
            <div class="section-title">
                Address & Role Details
            </div>

            <div class="form-group">
                <label>Barangay</label>

                <input type="text" name="barangay">
            </div>

            <div class="form-group">
                <label>City</label>

                <input type="text" name="city">
            </div>

            <div class="form-group">
                <label>Province</label>

                <input type="text" name="province">
            </div>

            <div class="form-group">
                <label>Zipcode</label>

                <input
                    type="text"
                    name="zipcode"
                    pattern="[0-9]{4}"
                    title="4-digit zipcode">
            </div>

            <!-- PATIENT SECTION -->
            <div id="section-patient" class="role-section">

                <div class="form-group">
                    <label>Birthday *</label>

                    <input type="date" name="dob">
                </div>

                <div class="form-group">

                    <label>Sex *</label>

                    <select name="sex">

                        <option value="male">Male</option>

                        <option value="female">Female</option>

                    </select>

                </div>

            </div>

            <!-- DOCTOR SECTION -->
            <div id="section-doctor" class="role-section">

                <div class="form-group">
                    <label>License Number *</label>

                    <input type="text" name="license_no">
                </div>

                <div class="form-group">
                    <label>Specialty *</label>

                    <input type="text" name="specialty">
                </div>

            </div>

        </div>

        <button type="submit" class="submit-btn">
            Create Account
        </button>

        <div class="login-link">
            Already have an account?
            <a href="login.php">Sign In</a>
        </div>

    </form>

</div>

<script>

function updateRoleUI(){

    const role = document.getElementById('roleSelect').value;

    const patientSection = document.getElementById('section-patient');

    const doctorSection = document.getElementById('section-doctor');

    patientSection.style.display =
        (role === 'patient') ? 'grid' : 'none';

    doctorSection.style.display =
        (role === 'doctor') ? 'grid' : 'none';
}

</script>

</body>
</html>