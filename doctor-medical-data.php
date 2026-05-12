<?php
/**
 * doctor-medical-data.php
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
$doctor_id = $_SESSION['user_id'];

try {
    // 1. Fetch Medical Data (Updated to join patient and users tables correctly)
    $stmt = $pdo->prepare("
        SELECT md.medical_history_id as id, md.created_at as record_date, md.diagnosis as data, 
               u.first_name, u.last_name 
        FROM medical_history md 
        JOIN patient p ON md.patient_id = p.patient_id
        JOIN users u ON p.user_id = u.user_id 
        WHERE md.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
        ORDER BY md.created_at DESC
    ");
    $stmt->execute();
    $requests = $stmt->fetchAll();
} catch (Exception $e) {
    $requests = [];
    $error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical History | Health4Q</title>
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

        /* --- CONTENT --- */
        .container { max-width: 1000px; margin: 40px auto; padding: 0 20px; }

        .header-section { margin-bottom: 30px; }
        .header-section h1 { font-size: 24px; color: var(--primary-green); font-weight: 800; }
        .header-section p { color: #666; font-size: 14px; }

        .data-card {
            background: var(--white);
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            overflow: hidden;
        }

        /* --- TABLE STYLING --- */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { 
            text-align: left; 
            padding: 15px; 
            font-size: 11px; 
            color: #888; 
            text-transform: uppercase; 
            letter-spacing: 1px;
            border-bottom: 2px solid #f0f4f8;
        }
        td { padding: 20px 15px; font-size: 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        
        .patient-name { font-weight: 700; color: var(--text-dark); display: block; }
        .date-text { color: #4361ee; font-weight: 600; font-size: 12px; }
        
        .summary-box {
            color: #555;
            line-height: 1.5;
            max-width: 400px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .action-link {
            color: var(--accent-green);
            text-decoration: none;
            font-weight: 700;
            font-size: 12px;
            padding: 8px 16px;
            background: #e9f5f2;
            border-radius: 8px;
            transition: 0.3s;
        }
        .action-link:hover { background: var(--primary-green); color: white; }

        /* --- EMPTY STATE --- */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        .empty-state img { width: 80px; opacity: 0.2; margin-bottom: 20px; }
        .empty-state p { color: #999; font-weight: 500; }
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
            <a href="doctor-appointment.php">Appointments</a>
            <a href="doctor-medical-data.php" class="active">Medical Data</a>
            <a href="issuance.php">Referrals</a>
        </div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </nav>

    <div class="container">
        <div class="header-section">
            <h1>Medical History Requests</h1>
            <p>Reviewing submissions from the last 90 days.</p>
        </div>

        <div class="data-card">
            <?php if (count($requests) > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Submission Date</th>
                            <th>Diagnosis Summary</th>
                            <th style="text-align: right;">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $request): ?>
                            <tr>
                                <td>
                                    <span class="patient-name">
                                        <?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="date-text">
                                        📅 <?php echo date('M d, Y', strtotime($request['record_date'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="summary-box">
                                        <?php echo htmlspecialchars(substr($request['data'], 0, 80)); ?>...
                                    </div>
                                </td>
                                <td style="text-align: right;">
                                    <a href="view-medical-record.php?id=<?php echo $request['id']; ?>" class="action-link">Open Record</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty-state">
                    <p>No medical data requests found.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>