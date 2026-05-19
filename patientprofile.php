<?php
/**
 * patientprofile.php - Full Functional Forest Green Theme with Insurance Management
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
$error = '';

try {
    // 1. Fetch Patient & User Data
    $stmt = $pdo->prepare('SELECT u.email, u.first_name, u.last_name, p.* 
                           FROM users u 
                           JOIN patient p ON u.user_id = p.user_id 
                           WHERE u.user_id = ?');
    $stmt->execute([$user_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        throw new Exception("Patient record not found.");
    }

    // 2. Fetch Patient Insurance details
    $stmtIns = $pdo->prepare('SELECT * FROM patient_insurance WHERE patient_id = ? LIMIT 1');
    $stmtIns->execute([$patient['patient_id']]);
    $insurance = $stmtIns->fetch(PDO::FETCH_ASSOC);

    // 3. Handle Personal Profile Update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_personal') {
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $emergency = trim($_POST['emergency_contact'] ?? '');
        $blood_type = trim($_POST['blood_type'] ?? '');

        // Database Update
        $updateStmt = $pdo->prepare('UPDATE patient SET phone = ?, address = ?, emergency_contact = ?, blood_type = ? WHERE user_id = ?');
        
        if ($updateStmt->execute([$phone, $address, $emergency, $blood_type, $user_id])) {
            header("Location: patientprofile.php?success=1");
            exit;
        } else {
            $error = "Unable to update profile. Please try again.";
        }
    }

    // 4. Handle Insurance Policy Update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_insurance') {
        $provider_name = trim($_POST['provider_name'] ?? '');
        $policy_number = trim($_POST['policy_number'] ?? '');
        $group_number = trim($_POST['group_number'] ?? '');
        $coverage_type = trim($_POST['coverage_type'] ?? '');
        $effective_date = !empty($_POST['effective_date']) ? $_POST['effective_date'] : null;
        $expiry_date = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;

        if (!empty($provider_name) && !empty($policy_number)) {
            if ($insurance) {
                // Update existing record
                $stmtUpdateIns = $pdo->prepare('
                    UPDATE patient_insurance 
                    SET provider_name = ?, policy_number = ?, group_number = ?, coverage_type = ?, effective_date = ?, expiry_date = ?, is_active = 1
                    WHERE patient_id = ?
                ');
                $stmtUpdateIns->execute([$provider_name, $policy_number, $group_number, $coverage_type, $effective_date, $expiry_date, $patient['patient_id']]);
            } else {
                // Insert new record
                $stmtInsertIns = $pdo->prepare('
                    INSERT INTO patient_insurance (patient_id, provider_name, policy_number, group_number, coverage_type, effective_date, expiry_date, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                ');
                $stmtInsertIns->execute([$patient['patient_id'], $provider_name, $policy_number, $group_number, $coverage_type, $effective_date, $expiry_date]);
            }
            header("Location: patientprofile.php?success=1");
            exit;
        } else {
            $error = "Insurance Provider and Policy Number are required.";
        }
    }

} catch (Exception $e) {
    $error = 'System Error: ' . $e->getMessage();
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
            --primary: #1a4d2e; 
            --secondary: #4f772d; 
            --bg: #f4f9f4; 
            --surface: #ffffff;
            --text-main: #1c2a1c;
            --text-muted: #6b7280;
            --border: #e5e7eb;
            --success: #16a34a;
            --error: #dc2626;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background-color: var(--bg); color: var(--text-main); line-height: 1.6; }

        /* --- NAVIGATION --- */
        .header-nav {
            background: var(--primary); padding: 12px 5%; display: flex;
            justify-content: space-between; align-items: center;
            position: sticky; top: 0; z-index: 1000; box-shadow: 0 4px 12px rgba(0,0,0,0.1);
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
            transition: 0.3s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .nav-links a:hover, .nav-links a.active { background: var(--secondary); }

        .logout-btn {
            background: #d90429;
            color: white;
            padding: 8px 18px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: bold;
            font-size: 12px;
            transition: 0.3s;
        }
        .logout-btn:hover { background: #b00220; }

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

        /* --- FORMS --- */
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
        @media (max-width: 768px) { 
            .grid-2, .grid-3 { grid-template-columns: 1fr; } 
        }

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
            padding: 16px; border-radius: 12px; margin-bottom: 30px; font-weight: 600; 
            display: flex; align-items: center; gap: 10px;
        }
        .alert-success { background: #dcfce7; color: #166534; }
        .alert-error { background: #fee2e2; color: #991b1b; }

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
            <a href="patient-dashboard.php">🏠 Dashboard</a>
            <a href="patientprofile.php" class="active">👤 Profile</a>
            <a href="patientappoint.php">📅 Appointments</a>
            <a href="patient-prescriptions.php">💊 Prescriptions</a>
            <a href="patient-lab-results.php">🧪 Lab Results</a>
            <a href="patientmedhist.php">📜 History</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </nav>

    <div class="container">
        
        <!-- Welcome Header -->
        <div class="profile-header">
            <div class="avatar-circle">
                <?php echo strtoupper(substr($patient['first_name'] ?? 'P', 0, 1)); ?>
            </div>
            <div>
                <h1 style="color: var(--primary);"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h1>
                <p style="color: var(--text-muted);">Patient ID: #P-<?php echo str_pad($patient['patient_id'], 5, '0', STR_PAD_LEFT); ?></p>
            </div>
        </div>

        <!-- Notification Alerts -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">✓ Profile updated successfully! Your changes are now live.</div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="grid-2">
            <!-- Updateable Section -->
            <section class="card">
                <h3 class="card-title">📞 Contact & Profile Details</h3>
                <form action="patientprofile.php" method="POST">
                    <input type="hidden" name="action" value="update_personal">
                    
                    <div class="form-group">
                        <label>Email Address (Primary)</label>
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
                        <label>Blood Type</label>
                        <select name="blood_type" class="input-control">
                            <option value="" <?php echo empty($patient['blood_type']) ? 'selected' : ''; ?>>Not Specified</option>
                            <option value="A+" <?php echo ($patient['blood_type'] ?? '') === 'A+' ? 'selected' : ''; ?>>A+</option>
                            <option value="A-" <?php echo ($patient['blood_type'] ?? '') === 'A-' ? 'selected' : ''; ?>>A-</option>
                            <option value="B+" <?php echo ($patient['blood_type'] ?? '') === 'B+' ? 'selected' : ''; ?>>B+</option>
                            <option value="B-" <?php echo ($patient['blood_type'] ?? '') === 'B-' ? 'selected' : ''; ?>>B-</option>
                            <option value="AB+" <?php echo ($patient['blood_type'] ?? '') === 'AB+' ? 'selected' : ''; ?>>AB+</option>
                            <option value="AB-" <?php echo ($patient['blood_type'] ?? '') === 'AB-' ? 'selected' : ''; ?>>AB-</option>
                            <option value="O+" <?php echo ($patient['blood_type'] ?? '') === 'O+' ? 'selected' : ''; ?>>O+</option>
                            <option value="O-" <?php echo ($patient['blood_type'] ?? '') === 'O-' ? 'selected' : ''; ?>>O-</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Home Address</label>
                        <textarea name="address" class="input-control" rows="2" style="resize: none;"><?php echo htmlspecialchars($patient['address'] ?? ''); ?></textarea>
                    </div>

                    <button type="submit" class="btn-primary">Save Changes</button>
                </form>
            </section>

            <!-- Locked Medical Section -->
            <section class="card">
                <h3 class="card-title">🔒 Medical Baseline</h3>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 25px;">
                    This clinical data is locked for security. To request changes, please visit our clinic with a valid ID.
                </p>
                
                <div class="grid-2">
                    <div class="form-group">
                        <label>Blood Type</label>
                        <div class="input-control locked"><?php echo htmlspecialchars($patient['blood_type'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="form-group">
                        <label>Sex</label>
                        <div class="input-control locked"><?php echo htmlspecialchars(ucfirst($patient['sex'] ?? 'N/A')); ?></div>
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

        <!-- Insurance Card section (NEW FEATURE!) -->
        <section class="card">
            <h3 class="card-title">💳 Insurance Policy & Coverage</h3>
            <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 25px;">
                Add or modify your active healthcare insurance coverage. This information will be used for laboratory, referrals, and medical authorization verification.
            </p>
            <form action="patientprofile.php" method="POST">
                <input type="hidden" name="action" value="update_insurance">
                
                <div class="grid-3" style="margin-bottom: 20px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Insurance Provider *</label>
                        <input type="text" name="provider_name" class="input-control" value="<?php echo htmlspecialchars($insurance['provider_name'] ?? ''); ?>" placeholder="e.g. PhilHealth, Maxicare, Intellicare" required>
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Policy Number *</label>
                        <input type="text" name="policy_number" class="input-control" value="<?php echo htmlspecialchars($insurance['policy_number'] ?? ''); ?>" placeholder="e.g. POL-102938475" required>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Group / Plan Number</label>
                        <input type="text" name="group_number" class="input-control" value="<?php echo htmlspecialchars($insurance['group_number'] ?? ''); ?>" placeholder="e.g. GRP-4829">
                    </div>
                </div>

                <div class="grid-3" style="margin-bottom: 25px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Coverage / Plan Type</label>
                        <select name="coverage_type" class="input-control">
                            <option value="HMO" <?php echo ($insurance['coverage_type'] ?? '') === 'HMO' ? 'selected' : ''; ?>>HMO (Health Maintenance Org)</option>
                            <option value="Private" <?php echo ($insurance['coverage_type'] ?? '') === 'Private' ? 'selected' : ''; ?>>Private Health Insurance</option>
                            <option value="Government" <?php echo ($insurance['coverage_type'] ?? '') === 'Government' ? 'selected' : ''; ?>>Government Coverage</option>
                            <option value="Dental/Vision" <?php echo ($insurance['coverage_type'] ?? '') === 'Dental/Vision' ? 'selected' : ''; ?>>Dental & Vision Specialist</option>
                        </select>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Effective Date</label>
                        <input type="date" name="effective_date" class="input-control" value="<?php echo htmlspecialchars($insurance['effective_date'] ?? ''); ?>">
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label>Expiration Date</label>
                        <input type="date" name="expiry_date" class="input-control" value="<?php echo htmlspecialchars($insurance['expiry_date'] ?? ''); ?>">
                    </div>
                </div>

                <button type="submit" class="btn-primary">💾 Save Insurance details</button>
            </form>
        </section>

    </div>

</body>
</html>